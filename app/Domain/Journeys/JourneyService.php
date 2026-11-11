<?php

namespace App\Domain\Journeys;

use App\Domain\Tasks\TaskAssignmentNotifier;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\JourneyStepExecution;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class JourneyService
{
    public function ensureDefaultTemplate(Organization $organization): JourneyTemplate
    {
        return DB::transaction(function () use ($organization): JourneyTemplate {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            app(TenantContext::class)->set($lockedOrganization);

            $default = JourneyTemplate::query()
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();

            if ($default !== null) {
                return $default;
            }

            $template = JourneyTemplate::query()->firstOrCreate(
                ['name' => 'Prospecção Comercial'],
                [
                    'organization_id' => $lockedOrganization->id,
                    'description' => 'Organiza os primeiros contatos e acompanhamentos dos prospectos.',
                    'type' => 'prospecting',
                    'trigger_type' => 'company_created',
                    'is_active' => true,
                    'is_default' => true,
                ],
            );
            $template->forceFill([
                'is_active' => true,
                'is_default' => true,
                'trigger_type' => 'company_created',
            ])->save();

            if (! $template->steps()->exists()) {
                foreach ($this->defaultSteps() as $step) {
                    $template->steps()->create($step);
                }
            }

            return $template->load('steps');
        });
    }

    public function enrollDefaultForProspect(Company $company, User $actor): ?JourneyEnrollment
    {
        $organization = app(TenantContext::class)->organization();

        if ($organization === null
            || ! $organization->auto_enroll_default_journey
            || $company->relationship_status !== 'prospect') {
            return null;
        }

        $template = JourneyTemplate::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($template === null) {
            return null;
        }

        return $this->enroll($company, $template, $actor);
    }

    public function enrollManually(Company $company, JourneyTemplate $template, User $actor): JourneyEnrollment
    {
        if ($company->relationship_status !== 'prospect') {
            throw ValidationException::withMessages([
                'journey_template_id' => 'A jornada de prospecção só pode ser iniciada para um prospecto.',
            ]);
        }

        return $this->enroll($company, $template, $actor);
    }

    public function enroll(Company $company, JourneyTemplate $template, User $actor): JourneyEnrollment
    {
        return DB::transaction(function () use ($company, $template, $actor): JourneyEnrollment {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $existing = JourneyEnrollment::query()
                ->where('journey_template_id', $template->id)
                ->where('company_id', $lockedCompany->id)
                ->whereIn('status', ['active', 'paused'])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $firstStep = $template->steps()->orderBy('position')->first();

            if ($firstStep === null) {
                throw ValidationException::withMessages([
                    'journey_template_id' => 'O modelo precisa ter pelo menos uma etapa ativa.',
                ]);
            }

            $now = CarbonImmutable::now('UTC');
            $assigneeId = $lockedCompany->owner_user_id ?? $actor->id;
            $enrollment = JourneyEnrollment::query()->create([
                'journey_template_id' => $template->id,
                'company_id' => $lockedCompany->id,
                'assigned_to' => $assigneeId,
                'status' => 'active',
                'enrolled_at' => $now,
            ]);

            $this->activateStep($enrollment, $firstStep, $now);

            return $enrollment->load('template', 'company', 'executions.step', 'tasks');
        });
    }

    public function completeTask(Task $task, User $user, ?string $completionNotes): Task
    {
        return DB::transaction(function () use ($task, $user, $completionNotes): Task {
            $currentTask = Task::query()->whereKey($task->id)->firstOrFail();
            $enrollment = $currentTask->journey_enrollment_id === null
                ? null
                : JourneyEnrollment::query()->lockForUpdate()->whereKey($currentTask->journey_enrollment_id)->firstOrFail();
            $lockedTask = Task::query()
                ->with('journeyStepExecution.step')
                ->lockForUpdate()
                ->whereKey($task->id)
                ->firstOrFail();

            if (! in_array($lockedTask->status, ['pending', 'in_progress', 'postponed'], true)) {
                return $lockedTask;
            }

            Gate::authorize('complete', $lockedTask);

            $completedAt = CarbonImmutable::now('UTC');
            $lockedTask->update([
                'status' => 'completed',
                'started_at' => $lockedTask->started_at ?? $completedAt,
                'completed_at' => $completedAt,
                'completed_by' => $user->id,
                'completion_notes' => $completionNotes,
            ]);

            $execution = $lockedTask->journeyStepExecution;

            if ($execution === null) {
                return $lockedTask->refresh();
            }

            $execution->update(['status' => 'completed', 'completed_at' => $completedAt]);
            $enrollment ??= JourneyEnrollment::query()->lockForUpdate()->whereKey($execution->journey_enrollment_id)->firstOrFail();

            if ($enrollment->status === 'active') {
                $this->activateNextStep($enrollment, $execution->step, $completedAt);
            }

            return $lockedTask->refresh();
        });
    }

    public function pause(JourneyEnrollment $enrollment): JourneyEnrollment
    {
        return DB::transaction(function () use ($enrollment): JourneyEnrollment {
            $locked = JourneyEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            if ($locked->status === 'active') {
                $locked->update(['status' => 'paused', 'paused_at' => CarbonImmutable::now('UTC')]);
            }

            return $locked->refresh();
        });
    }

    public function resume(JourneyEnrollment $enrollment): JourneyEnrollment
    {
        return DB::transaction(function () use ($enrollment): JourneyEnrollment {
            $locked = JourneyEnrollment::query()
                ->lockForUpdate()
                ->with(['template.steps', 'executions.step'])
                ->findOrFail($enrollment->id);

            if ($locked->status !== 'paused') {
                return $locked;
            }

            $locked->update(['status' => 'active']);
            $latestExecution = $locked->executions
                ->sortBy(fn (JourneyStepExecution $execution): int => $execution->step->position)
                ->last();

            if ($latestExecution?->status === 'completed') {
                $this->activateNextStep($locked, $latestExecution->step, $latestExecution->completed_at);
            }

            return $locked->refresh();
        });
    }

    public function cancel(JourneyEnrollment $enrollment): JourneyEnrollment
    {
        return DB::transaction(function () use ($enrollment): JourneyEnrollment {
            $locked = JourneyEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            if (in_array($locked->status, ['active', 'paused'], true)) {
                $locked->update(['status' => 'cancelled', 'cancelled_at' => CarbonImmutable::now('UTC')]);
                $locked->tasks()
                    ->where('origin', 'automatic')
                    ->whereIn('status', ['pending', 'in_progress', 'postponed'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            return $locked->refresh();
        });
    }

    public function reassignOpenTasks(Company $company, ?int $ownerUserId, User $fallbackAssignee): void
    {
        DB::transaction(function () use ($company, $ownerUserId, $fallbackAssignee): void {
            $assigneeId = $ownerUserId ?? $fallbackAssignee->id;
            $enrollments = JourneyEnrollment::query()
                ->where('company_id', $company->id)
                ->whereIn('status', ['active', 'paused'])
                ->lockForUpdate()
                ->get();

            foreach ($enrollments as $enrollment) {
                $enrollment->update(['assigned_to' => $assigneeId]);
                $tasks = $enrollment->tasks()
                    ->where('origin', 'automatic')
                    ->whereIn('status', ['pending', 'in_progress', 'postponed'])
                    ->lockForUpdate()
                    ->get();

                foreach ($tasks as $task) {
                    if ($task->assigned_to === $assigneeId) {
                        continue;
                    }

                    $task->update(['assigned_to' => $assigneeId]);
                    app(TaskAssignmentNotifier::class)->notify(
                        $task->refresh(),
                        'task_assigned',
                        'Nova atividade atribuída',
                        'Uma atividade da jornada foi atribuída a você.',
                    );
                }
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function defaultSteps(): array
    {
        return [
            ['title' => 'Primeiro contato', 'description' => 'Apresente a empresa e conheça as necessidades do escritório.', 'position' => 1, 'delay_days' => 1, 'task_title' => 'Realizar primeiro contato com {company}.', 'task_description' => null, 'task_type' => 'first_contact', 'task_priority' => 'normal'],
            ['title' => 'Primeiro acompanhamento', 'description' => 'Retome o contato e acompanhe a oportunidade.', 'position' => 2, 'delay_days' => 7, 'task_title' => 'Fazer acompanhamento comercial com {company}.', 'task_description' => null, 'task_type' => 'follow_up', 'task_priority' => 'normal'],
            ['title' => 'Segundo acompanhamento', 'description' => 'Verifique as oportunidades comerciais em aberto.', 'position' => 3, 'delay_days' => 15, 'task_title' => 'Retomar contato e verificar oportunidades comerciais com {company}.', 'task_description' => null, 'task_type' => 'follow_up', 'task_priority' => 'normal'],
            ['title' => 'Revisão do prospecto', 'description' => 'Revise a situação e defina os próximos passos.', 'position' => 4, 'delay_days' => 30, 'task_title' => 'Revisar situação comercial e definir próximos passos para {company}.', 'task_description' => null, 'task_type' => 'follow_up', 'task_priority' => 'normal'],
        ];
    }

    private function activateNextStep(JourneyEnrollment $enrollment, JourneyStep $completedStep, CarbonImmutable $completedAt): void
    {
        $nextStep = $enrollment->template->steps()
            ->where('position', '>', $completedStep->position)
            ->orderBy('position')
            ->first();

        if ($nextStep === null) {
            if ($enrollment->status !== 'completed') {
                $enrollment->update(['status' => 'completed', 'completed_at' => CarbonImmutable::now('UTC')]);
                app(TaskAssignmentNotifier::class)->notifyJourneyCompleted($enrollment->refresh());
            }

            return;
        }

        $this->activateStep($enrollment, $nextStep, $completedAt);
    }

    private function activateStep(JourneyEnrollment $enrollment, JourneyStep $step, CarbonImmutable $anchor): void
    {
        $organization = app(TenantContext::class)->organization();
        $dueAt = $anchor->setTimezone($organization->timezone)
            ->addDays($step->delay_days)
            ->utc();
        $execution = JourneyStepExecution::query()->firstOrCreate(
            [
                'journey_enrollment_id' => $enrollment->id,
                'journey_step_id' => $step->id,
            ],
            [
                'status' => 'active',
                'scheduled_at' => $dueAt,
                'activated_at' => CarbonImmutable::now('UTC'),
            ],
        );

        $task = Task::query()->firstOrCreate(
            ['journey_step_execution_id' => $execution->id],
            [
                'company_id' => $enrollment->company_id,
                'assigned_to' => $enrollment->assigned_to,
                'created_by' => $enrollment->assigned_to,
                'title' => str_replace('{company}', $enrollment->company->name, $step->task_title),
                'description' => $step->task_description,
                'type' => $step->task_type,
                'priority' => $step->task_priority,
                'status' => 'pending',
                'due_at' => $dueAt,
                'origin' => 'automatic',
                'journey_enrollment_id' => $enrollment->id,
                'journey_step_execution_id' => $execution->id,
            ],
        );

        if ($task->wasRecentlyCreated) {
            app(TaskAssignmentNotifier::class)->notify(
                $task,
                'journey_step_activated',
                'Nova etapa da jornada',
                'Uma nova etapa da jornada foi atribuída a você.',
            );
        }
    }
}
