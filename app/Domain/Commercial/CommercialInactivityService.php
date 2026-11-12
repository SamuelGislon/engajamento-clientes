<?php

namespace App\Domain\Commercial;

use App\Domain\Journeys\JourneyService;
use App\Domain\Tasks\TaskAssignmentNotifier;
use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JourneyEnrollment;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CommercialInactivityService
{
    /** @return array{organizations: int, companies: int, tasks_created: int} */
    public function scanAllOrganizations(): array
    {
        $totals = ['organizations' => 0, 'companies' => 0, 'tasks_created' => 0];

        Organization::query()->orderBy('id')->chunkById(50, function ($organizations) use (&$totals): void {
            foreach ($organizations as $organization) {
                $result = $this->scanOrganization($organization);
                $totals['organizations']++;
                $totals['companies'] += $result['companies'];
                $totals['tasks_created'] += $result['tasks_created'];
            }
        });

        return $totals;
    }

    /** @return array{companies: int, tasks_created: int} */
    public function scanOrganization(Organization $organization): array
    {
        app(TenantContext::class)->set($organization);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        $now = CarbonImmutable::now('UTC');
        $salesId = Sale::query()
            ->select('id')
            ->whereColumn('company_id', 'companies.id')
            ->where('status', 'confirmed')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit(1);
        $salesAt = Sale::query()
            ->select('sold_at')
            ->whereColumn('company_id', 'companies.id')
            ->where('status', 'confirmed')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit(1);
        $activityId = Activity::query()
            ->select('id')
            ->whereColumn('company_id', 'companies.id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(1);
        $activityAt = Activity::query()
            ->select('occurred_at')
            ->whereColumn('company_id', 'companies.id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(1);
        $companies = Company::query()
            ->select('companies.*')
            ->selectSub($salesId, 'last_confirmed_sale_id')
            ->selectSub($salesAt, 'last_confirmed_sale_at')
            ->selectSub($activityId, 'last_activity_id')
            ->selectSub($activityAt, 'last_activity_at')
            ->with(['owner:id,name', 'customerScore', 'contacts:id,name,job_title'])
            ->orderBy('companies.id');
        $totals = ['companies' => 0, 'tasks_created' => 0];

        $companies->chunkById(100, function ($batch) use ($settings, $now, &$totals): void {
            foreach ($batch as $company) {
                $totals['companies']++;

                if ($company->owner_user_id === null) {
                    continue;
                }

                if ($company->last_confirmed_sale_id !== null && $company->last_confirmed_sale_at !== null) {
                    $lastSaleAt = CarbonImmutable::parse($company->last_confirmed_sale_at, 'UTC');
                    $daysWithoutPurchase = $this->elapsedCalendarDays($lastSaleAt, $now);

                    if (! $lastSaleAt->greaterThan($now)
                        && $daysWithoutPurchase >= $settings->sales_inactivity_days) {
                        $recommendedContact = $this->recommendedContact($company);
                        $baseKey = 'sale-'.$company->last_confirmed_sale_id.'-days-'.$settings->sales_inactivity_days;
                        $totals['tasks_created'] += (int) $this->createOccurrenceTask(
                            $company,
                            'commercial_inactivity',
                            $this->occurrenceKeyAfterCooldown(
                                $company,
                                'commercial_inactivity',
                                $baseKey,
                                $settings->reactivation_repeat_cooldown_days,
                            ),
                            $lastSaleAt,
                            $this->commercialReason($company, $lastSaleAt, $daysWithoutPurchase, $recommendedContact),
                            'Reativar relacionamento com '.$company->name,
                            'reactivation',
                            $recommendedContact,
                            $settings->reactivation_journey_template_id === null
                                ? null
                                : (int) $settings->reactivation_journey_template_id,
                        );
                    } else {
                        $this->resolveOccurrences($company, 'commercial_inactivity', 'Condição de compra recente ou intervalo atualizado.');
                    }
                } else {
                    $this->resolveOccurrences($company, 'commercial_inactivity', 'Não há compras confirmadas para manter a inatividade comercial.');
                }

                $lastActivityAt = $company->last_activity_at === null
                    ? CarbonImmutable::parse($company->created_at, 'UTC')
                    : CarbonImmutable::parse($company->last_activity_at, 'UTC');
                $tier = $company->customerScore?->tier;
                $relationshipInterval = $tier !== null
                    ? (int) ($settings->contact_frequency_days[$tier] ?? $settings->relationship_inactivity_days)
                    : $settings->relationship_inactivity_days;
                $daysWithoutContact = $this->elapsedCalendarDays($lastActivityAt, $now);

                if (! $lastActivityAt->greaterThan($now)
                    && $daysWithoutContact >= $relationshipInterval) {
                    $recommendedContact = $this->recommendedContact($company);
                    $baseKey = 'activity-'.($company->last_activity_id ?? 'none').'-anchor-'.$lastActivityAt->format('YmdHis').'-days-'.$relationshipInterval;
                    $totals['tasks_created'] += (int) $this->createOccurrenceTask(
                        $company,
                        'relationship_inactivity',
                        $this->occurrenceKeyAfterCooldown(
                            $company,
                            'relationship_inactivity',
                            $baseKey,
                            $relationshipInterval,
                        ),
                        $lastActivityAt,
                        $this->relationshipReason($company, $lastActivityAt, $daysWithoutContact, $company->last_activity_id === null, $recommendedContact),
                        'Retomar contato com '.$company->name,
                        'follow_up',
                        $this->recommendedContact($company),
                        null,
                    );
                } else {
                    $this->resolveOccurrences($company, 'relationship_inactivity', 'Interação recente ou intervalo atualizado.');
                }
            }
        }, 'companies.id', 'id');

        return $totals;
    }

    public function resolveCommercialForCompany(Company $company, string $reason = 'Nova compra confirmada.'): void
    {
        $this->resolveOccurrences($company, 'commercial_inactivity', $reason);
    }

    public function resolveRelationshipForCompany(Company $company, string $reason = 'Nova interação registrada.'): void
    {
        $this->resolveOccurrences($company, 'relationship_inactivity', $reason);
    }

    public function refreshCommercialForCompany(Company $company): void
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        $record = Company::query()->with(['owner:id,name', 'customerScore', 'contacts:id,name,job_title'])->findOrFail($company->id);
        $lastSale = $record->sales()
            ->where('status', 'confirmed')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->first(['id', 'sold_at']);
        $record->setAttribute('last_confirmed_sale_id', $lastSale?->id);
        $record->setAttribute('last_confirmed_sale_at', $lastSale?->sold_at);

        if ($record->owner_user_id === null) {
            $this->resolveOccurrences($record, 'commercial_inactivity', 'A empresa não possui responsável comercial atribuído.');

            return;
        }

        $now = CarbonImmutable::now('UTC');

        if ($lastSale === null) {
            $this->resolveOccurrences($record, 'commercial_inactivity', 'Não há compras confirmadas para manter a inatividade comercial.');

            return;
        }

        $lastSaleAt = CarbonImmutable::parse($lastSale->sold_at, 'UTC');
        $daysWithoutPurchase = $this->elapsedCalendarDays($lastSaleAt, $now);

        if ($lastSaleAt->greaterThan($now) || $daysWithoutPurchase < $settings->sales_inactivity_days) {
            $this->resolveOccurrences($record, 'commercial_inactivity', 'Condição de compra recente ou intervalo atualizado.');

            return;
        }

        $recommendedContact = $this->recommendedContact($record);
        $baseKey = 'sale-'.$lastSale->id.'-days-'.$settings->sales_inactivity_days;
        $this->createOccurrenceTask(
            $record,
            'commercial_inactivity',
            $this->occurrenceKeyAfterCooldown($record, 'commercial_inactivity', $baseKey, $settings->reactivation_repeat_cooldown_days),
            $lastSaleAt,
            $this->commercialReason($record, $lastSaleAt, $daysWithoutPurchase, $recommendedContact),
            'Reativar relacionamento com '.$record->name,
            'reactivation',
            $recommendedContact,
            $settings->reactivation_journey_template_id === null ? null : (int) $settings->reactivation_journey_template_id,
        );
    }

    public function reassignOpenStandaloneTasks(Company $company, ?int $ownerUserId, User $fallbackAssignee): void
    {
        $assigneeId = $ownerUserId ?? $fallbackAssignee->id;
        $tasks = Task::query()
            ->where('company_id', $company->id)
            ->whereNull('journey_enrollment_id')
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
                'Uma atividade comercial automática foi atribuída a você.',
            );
        }
    }

    private function createOccurrenceTask(
        Company $company,
        string $type,
        string $occurrenceKey,
        CarbonImmutable $anchorAt,
        string $reason,
        string $title,
        string $taskType,
        ?Contact $recommendedContact,
        ?int $reactivationJourneyTemplateId,
    ): bool {
        return DB::transaction(function () use ($company, $type, $occurrenceKey, $anchorAt, $reason, $title, $taskType, $recommendedContact, $reactivationJourneyTemplateId): bool {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $occurrence = CommercialAlertOccurrence::query()
                ->where('company_id', $lockedCompany->id)
                ->where('type', $type)
                ->where('occurrence_key', $occurrenceKey)
                ->lockForUpdate()
                ->first();

            if ($occurrence !== null) {
                if ($occurrence->task_id !== null) {
                    $task = Task::query()->find($occurrence->task_id);

                    if ($task !== null && in_array($task->status, ['completed', 'cancelled'], true)) {
                        $occurrence->update([
                            'status' => 'resolved',
                            'resolved_at' => $task->completed_at ?? $task->updated_at ?? CarbonImmutable::now('UTC'),
                            'resolution_reason' => $task->status === 'completed'
                                ? 'A tarefa vinculada foi concluída.'
                                : 'A tarefa vinculada foi cancelada; o evento não será recriado.',
                        ]);

                        if ($task->status === 'cancelled') {
                            $this->cancelAutomaticTaskOrEnrollment($task, $type);
                        }
                    }
                }

                return false;
            }

            $previousOccurrences = CommercialAlertOccurrence::query()
                ->where('company_id', $lockedCompany->id)
                ->where('type', $type)
                ->where('status', 'active')
                ->where('occurrence_key', '!=', $occurrenceKey)
                ->lockForUpdate()
                ->get();

            foreach ($previousOccurrences as $previousOccurrence) {
                $this->resolveSingleOccurrence($previousOccurrence, 'A condição foi reavaliada com um novo marco de referência.');
            }

            $occurrence = CommercialAlertOccurrence::query()->create([
                'company_id' => $lockedCompany->id,
                'type' => $type,
                'occurrence_key' => $occurrenceKey,
                'anchor_at' => $anchorAt,
                'status' => 'active',
                'reason' => $reason,
                'first_detected_at' => CarbonImmutable::now('UTC'),
                'recommended_contact_id' => $recommendedContact?->id,
            ]);

            $equivalentJourneyTasks = Task::query()
                ->where('company_id', $lockedCompany->id)
                ->whereNotNull('journey_enrollment_id')
                ->where('origin', 'automatic')
                ->whereIn('status', ['pending', 'in_progress', 'postponed'])
                ->whereIn('type', ['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'follow_up', 'reactivation'])
                ->orderBy('due_at')
                ->orderBy('id');

            if ($type === 'commercial_inactivity') {
                $equivalentJourneyTasks->whereHas('journeyEnrollment.template', fn ($templates) => $templates
                    ->where('type', 'reactivation')
                    ->where('trigger_type', 'sales_inactivity'));
            } else {
                $equivalentJourneyTasks
                    ->where('due_at', '<=', CarbonImmutable::now('UTC'))
                    ->whereHas('journeyEnrollment.template', fn ($templates) => $templates->where('type', '!=', 'reactivation'));
            }

            $equivalentJourneyTask = $equivalentJourneyTasks->first();

            if ($equivalentJourneyTask !== null) {
                $occurrence->update(['task_id' => $equivalentJourneyTask->id]);

                return false;
            }

            $assigneeId = $lockedCompany->owner_user_id;

            if ($assigneeId === null) {
                $occurrence->delete();

                return false;
            }

            if ($type === 'commercial_inactivity' && $reactivationJourneyTemplateId !== null) {
                $template = JourneyTemplate::query()
                    ->whereKey($reactivationJourneyTemplateId)
                    ->where('type', 'reactivation')
                    ->where('trigger_type', 'sales_inactivity')
                    ->where('is_active', true)
                    ->first();

                if ($template !== null) {
                    $existingEnrollmentId = JourneyEnrollment::query()
                        ->where('journey_template_id', $template->id)
                        ->where('company_id', $lockedCompany->id)
                        ->whereIn('status', ['active', 'paused'])
                        ->value('id');
                    $enrollment = app(JourneyService::class)->enroll(
                        $lockedCompany,
                        $template,
                        User::query()->findOrFail($assigneeId),
                    );
                    $journeyTask = $enrollment->tasks()
                        ->whereIn('status', ['pending', 'in_progress', 'postponed'])
                        ->orderBy('due_at')
                        ->first();

                    if ($journeyTask !== null) {
                        if ($journeyTask->contact_id === null && $recommendedContact !== null) {
                            $journeyTask->update(['contact_id' => $recommendedContact->id]);
                        }

                        $occurrence->update(['task_id' => $journeyTask->id]);

                        return $existingEnrollmentId === null;
                    }

                    if ($enrollment->status === 'active' || $enrollment->status === 'paused') {
                        return false;
                    }
                }
            }

            $task = Task::query()->create([
                'company_id' => $lockedCompany->id,
                'contact_id' => $recommendedContact?->id,
                'assigned_to' => $assigneeId,
                'created_by' => $assigneeId,
                'title' => $title,
                'description' => $reason,
                'type' => $taskType,
                'priority' => $type === 'commercial_inactivity' ? 'high' : 'normal',
                'status' => 'pending',
                'due_at' => CarbonImmutable::now('UTC'),
                'origin' => 'automatic',
            ]);
            $occurrence->update(['task_id' => $task->id]);
            app(TaskAssignmentNotifier::class)->notify(
                $task,
                $type === 'commercial_inactivity' ? 'commercial_reactivation' : 'relationship_follow_up',
                $type === 'commercial_inactivity' ? 'Cliente sem compras recentes' : 'Cliente sem contato recente',
                $reason,
            );

            return true;
        });
    }

    private function resolveOccurrences(Company $company, string $type, string $reason): void
    {
        DB::transaction(function () use ($company, $type, $reason): void {
            $occurrences = CommercialAlertOccurrence::query()
                ->where('company_id', $company->id)
                ->where('type', $type)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($occurrences as $occurrence) {
                $this->resolveSingleOccurrence($occurrence, $reason);
            }
        });
    }

    private function resolveSingleOccurrence(CommercialAlertOccurrence $occurrence, string $reason): void
    {
        $occurrence->update([
            'status' => 'resolved',
            'resolved_at' => CarbonImmutable::now('UTC'),
            'resolution_reason' => mb_substr($reason, 0, 180),
        ]);

        if ($occurrence->task_id === null) {
            return;
        }

        $taskIsStillRequired = CommercialAlertOccurrence::query()
            ->where('task_id', $occurrence->task_id)
            ->where('status', 'active')
            ->exists();

        if ($taskIsStillRequired) {
            return;
        }

        $task = Task::query()->lockForUpdate()->find($occurrence->task_id);

        if ($task !== null) {
            $this->cancelAutomaticTaskOrEnrollment($task, $occurrence->type);
        }
    }

    private function cancelAutomaticTaskOrEnrollment(Task $task, string $occurrenceType): void
    {
        if ($task->origin !== 'automatic') {
            return;
        }

        if ($task->journey_enrollment_id !== null) {
            if ($occurrenceType !== 'commercial_inactivity') {
                return;
            }

            $enrollment = JourneyEnrollment::query()
                ->with('template')
                ->lockForUpdate()
                ->find($task->journey_enrollment_id);

            if ($enrollment !== null
                && $enrollment->template->type === 'reactivation'
                && $enrollment->template->trigger_type === 'sales_inactivity'
                && in_array($enrollment->status, ['active', 'paused'], true)) {
                app(JourneyService::class)->cancel($enrollment);
            }

            return;
        }

        if (in_array($task->status, ['pending', 'in_progress', 'postponed'], true)) {
            $task->update(['status' => 'cancelled']);
        }
    }

    private function occurrenceKeyAfterCooldown(Company $company, string $type, string $baseKey, int $cooldownDays): string
    {
        $latestOccurrence = CommercialAlertOccurrence::query()
            ->where('company_id', $company->id)
            ->where('type', $type)
            ->where(function ($query) use ($baseKey): void {
                $query->where('occurrence_key', $baseKey)
                    ->orWhere('occurrence_key', 'like', $baseKey.'-repeat-%');
            })
            ->orderByDesc('id')
            ->first();

        if ($latestOccurrence === null) {
            return $baseKey;
        }

        if ($latestOccurrence->status === 'active') {
            return $latestOccurrence->occurrence_key;
        }

        $closedAt = $latestOccurrence->resolved_at ?? $latestOccurrence->first_detected_at;

        if ($closedAt->addDays($cooldownDays)->isFuture()) {
            return $latestOccurrence->occurrence_key;
        }

        return $baseKey.'-repeat-'.$latestOccurrence->id;
    }

    private function elapsedCalendarDays(CarbonImmutable $anchorAt, CarbonImmutable $now): int
    {
        $timezone = app(TenantContext::class)->organization()->timezone;

        return (int) $anchorAt->setTimezone($timezone)->startOfDay()
            ->diffInDays($now->setTimezone($timezone)->startOfDay());
    }

    private function recommendedContact(Company $company): ?Contact
    {
        $rank = ['budget_manager' => 0, 'buyer' => 1, 'decision_maker' => 2];
        $candidates = $company->contacts
            ->filter(function ($contact) use ($rank): bool {
                $roles = $contact->pivot->roles ?? [];

                return collect($roles)->contains(fn (string $role): bool => isset($rank[$role]))
                    || $contact->pivot->is_primary;
            })
            ->sort(function ($first, $second) use ($rank): int {
                $firstRoles = $first->pivot->roles ?? [];
                $secondRoles = $second->pivot->roles ?? [];
                $firstRank = collect($firstRoles)->map(fn (string $role): int => $rank[$role] ?? 10)->min();
                $secondRank = collect($secondRoles)->map(fn (string $role): int => $rank[$role] ?? 10)->min();
                $firstRank = $first->pivot->is_primary && $firstRank === null ? 3 : ($firstRank ?? 10);
                $secondRank = $second->pivot->is_primary && $secondRank === null ? 3 : ($secondRank ?? 10);

                return [$firstRank, $first->id] <=> [$secondRank, $second->id];
            });

        return $candidates->first();
    }

    private function commercialReason(Company $company, CarbonImmutable $lastPurchase, int $daysWithoutPurchase, ?Contact $contact): string
    {
        return $company->name.' está há '.$daysWithoutPurchase.' dias sem uma compra confirmada. '
            .'Última compra: '.$lastPurchase->setTimezone(app(TenantContext::class)->organization()->timezone)->format('d/m/Y').'. '
            .$this->contactRecommendationText($contact).'Sugestão: entrar em contato e verificar novos projetos ou oportunidades comerciais.';
    }

    private function relationshipReason(Company $company, CarbonImmutable $lastContact, int $daysWithoutContact, bool $neverContacted, ?Contact $recommendedContact): string
    {
        $lastInteraction = $neverContacted
            ? 'Nenhuma interação registrada desde o cadastro.'
            : 'Última interação: '.$lastContact->setTimezone(app(TenantContext::class)->organization()->timezone)->format('d/m/Y').'.';

        return $company->name.' está há '.$daysWithoutContact.' dias sem uma interação comercial registrada. '
            .$lastInteraction.' '.$this->contactRecommendationText($recommendedContact).'Sugestão: retomar o contato e definir uma próxima ação.';
    }

    private function contactRecommendationText(?Contact $contact): string
    {
        if ($contact === null) {
            return 'Contato recomendado: nenhum contato específico foi definido porque não há profissional com responsabilidade apropriada cadastrado. ';
        }

        return 'Contato recomendado: '.$contact->name
            .($contact->job_title ? ', '.$contact->job_title : '')
            .'. ';
    }
}
