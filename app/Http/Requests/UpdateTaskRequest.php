<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Task;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('task')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $task = $this->route('task');
        $organizationId = app(TenantContext::class)->id();
        $user = $this->user();
        $isSalesperson = $user?->roleIn($organizationId) === 'salesperson';
        $manualTaskRules = $task instanceof Task && $task->origin === 'manual';
        $assignmentRule = Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId);
        $requestedAssignee = $this->input('assigned_to', $task instanceof Task ? $task->assigned_to : null);
        if (! ($task instanceof Task) || (int) $requestedAssignee !== $task->assigned_to) {
            $assignmentRule->where('is_active', true);
        }

        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['sometimes', 'required', 'date'],
            'company_id' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)] : []),
                ...($manualTaskRules && $isSalesperson ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)->where('owner_user_id', $user->id)] : []),
            ],
            'contact_id' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [Rule::exists('company_contacts', 'contact_id')
                    ->where('organization_id', $organizationId)
                    ->where('company_id', $this->integer('company_id', (int) $task->company_id))] : []),
            ],
            'assigned_to' => [
                ...($manualTaskRules ? ['sometimes', 'nullable', 'integer'] : ['prohibited']),
                ...($manualTaskRules ? [$assignmentRule] : []),
                ...($manualTaskRules && $isSalesperson ? [Rule::in([$user->id])] : []),
            ],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $task = $this->route('task');
            $user = $this->user();
            $organizationId = app(TenantContext::class)->id();

            if (! $task instanceof Task || $task->origin !== 'manual'
                || $user === null || $organizationId === null
                || $user->roleIn($organizationId) !== 'manager') {
                return;
            }

            $companyId = array_key_exists('company_id', $this->all())
                ? $this->input('company_id')
                : $task->company_id;
            $company = $companyId === null ? null : Company::query()->visibleTo($user)->find((int) $companyId);

            if ($companyId !== null && $company === null) {
                $validator->errors()->add('company_id', 'A empresa está fora das equipes que você administra.');
            }

            if (! array_key_exists('assigned_to', $this->all())
                || (int) $this->input('assigned_to') === $task->assigned_to) {
                return;
            }

            $assigneeId = (int) $this->input('assigned_to');
            $isSelf = $assigneeId === $user->id;
            $isSellerInCompanyTeam = $company !== null && DB::table('team_memberships')
                ->where('organization_id', $organizationId)
                ->where('team_id', $company->team_id)
                ->where('user_id', $assigneeId)
                ->where('role', 'salesperson')
                ->where('is_active', true)
                ->exists();
            $isSellerInManagedTeam = $company === null && DB::table('team_memberships')
                ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
                ->where('team_memberships.organization_id', $organizationId)
                ->where('team_memberships.user_id', $assigneeId)
                ->where('team_memberships.role', 'salesperson')
                ->where('team_memberships.is_active', true)
                ->where('teams.status', 'active')
                ->whereExists(fn ($teams) => $teams->selectRaw('1')
                    ->from('team_memberships as manager_memberships')
                    ->whereColumn('manager_memberships.team_id', 'teams.id')
                    ->whereColumn('manager_memberships.organization_id', 'teams.organization_id')
                    ->where('manager_memberships.user_id', $user->id)
                    ->where('manager_memberships.role', 'manager')
                    ->where('manager_memberships.is_active', true))
                ->exists();

            if (! $isSelf && ! $isSellerInCompanyTeam && ! $isSellerInManagedTeam) {
                $validator->errors()->add('assigned_to', 'A tarefa só pode ser atribuída a você ou a um vendedor das equipes que administra.');
            }
        }];
    }
}
