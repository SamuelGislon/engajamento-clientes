<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Sales\SalesMetrics;
use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Teams\CompanyAssignmentService;
use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Http\Requests\AssignCompanyRequest;
use App\Http\Requests\StoreActivityRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JourneyEnrollment;
use App\Models\JourneyTemplate;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);

        $user = $request->user();
        $companies = Company::query()
            ->visibleTo($user)
            ->with(['owner:id,name'])
            ->withCount('contacts')
            ->withMax('activities', 'occurred_at')
            ->when($request->string('q')->trim()->isNotEmpty(), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($nested) => $nested
                    ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(trade_name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(city) LIKE LOWER(?)', [$term]));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('relationship_status', $request->string('status')))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('companies/index', [
            'companies' => $companies,
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Company::class);

        return Inertia::render('companies/create', [
            'members' => $this->assignableMembers($request->user()),
            'teams' => $this->assignableTeams($request->user()),
            'canLeaveUnassignedTeam' => in_array($request->user()->roleIn(app(TenantContext::class)->id()), ['owner', 'admin', 'salesperson'], true),
            'statuses' => [
                ['value' => 'prospect', 'label' => 'Prospecto'],
                ['value' => 'customer', 'label' => 'Cliente'],
                ['value' => 'inactive', 'label' => 'Inativo'],
                ['value' => 'partner', 'label' => 'Parceiro'],
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request, JourneyService $journeys, CustomerScoreService $scores): RedirectResponse
    {
        $attributes = $request->validated();
        $user = $request->user();

        if ($user->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value) {
            $attributes['owner_user_id'] = $user->id;
        }

        $company = DB::transaction(function () use ($attributes, $user, $journeys, $scores): Company {
            $company = Company::create($attributes);
            $journeys->enrollDefaultForProspect($company, $user);
            $scores->recalculateForCompany($company, 'Empresa cadastrada');

            return $company;
        });

        return redirect()->route('companies.show', $company->id)->with('success', 'Empresa cadastrada.');
    }

    public function show(Request $request, int $company): Response
    {
        $record = Company::query()
            ->with(['owner:id,name', 'team:id,name', 'contacts', 'customerScore', 'activities' => fn ($query) => $query
                ->with(['contact:id,name', 'user:id,name'])
                ->limit(20)])
            ->findOrFail($company);

        Gate::authorize('view', $record);

        $user = $request->user();
        $attachableContacts = Contact::query()
            ->visibleTo($user)
            ->whereDoesntHave('companies', fn ($companies) => $companies->where('companies.id', $record->id))
            ->orderBy('name')
            ->get(['id', 'name', 'job_title']);

        $journeys = JourneyEnrollment::query()
            ->visibleTo($user)
            ->where('company_id', $record->id)
            ->with([
                'template:id,name',
                'tasks' => fn ($tasks) => $tasks->with(['assignee:id,name', 'journeyStepExecution.step:id,title'])->orderBy('due_at'),
            ])
            ->latest('enrolled_at')
            ->get();
        $availableJourneys = $record->relationship_status === 'prospect'
            ? JourneyTemplate::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();
        $tasks = Task::query()
            ->visibleTo($user)
            ->where('company_id', $record->id)
            ->with(['contact:id,name', 'assignee:id,name', 'journeyEnrollment.template:id,name'])
            ->orderBy('due_at')
            ->limit(20)
            ->get();
        $sales = Sale::query()
            ->visibleTo($user)
            ->where('company_id', $record->id)
            ->with('seller:id,name')
            ->latest('sold_at')
            ->limit(10)
            ->get()
            ->each(function (Sale $sale) use ($user): void {
                $sale->setAttribute('can_manage', $user->can('update', $sale));
            });
        $scoreHistory = $record->customerScoreHistory()->limit(10)->get();
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $commercialSettings = app(CommercialSettingsService::class)->forOrganization($organization);
        $tierByKey = collect($commercialSettings->tiers)->keyBy('key');
        if ($record->customerScore !== null) {
            $tier = $tierByKey->get($record->customerScore->tier);
            $record->customerScore->setAttribute('tier_name', $tier['name'] ?? null);
            $record->customerScore->setAttribute('tier_color', $tier['color'] ?? null);
        }
        $scoreHistory->each(function ($entry) use ($tierByKey): void {
            $tier = $tierByKey->get($entry->tier);
            $entry->setAttribute('tier_name', $entry->tier_name ?? $tier['name'] ?? null);
            $entry->setAttribute('tier_color', $entry->tier_color ?? $tier['color'] ?? null);
        });
        $salesTimezone = app(TenantContext::class)->organization()->timezone;
        $role = $user->roleIn(app(TenantContext::class)->id());
        $assignmentHistory = $record->assignmentHistory()
            ->with([
                'previousOwner:id,name', 'newOwner:id,name',
                'previousTeam:id,name', 'newTeam:id,name', 'actor:id,name',
            ])
            ->limit(10)
            ->get();

        return Inertia::render('companies/show', [
            'company' => $record,
            'attachableContacts' => $attachableContacts,
            'members' => $this->assignableMembers($user),
            'saleSellers' => $this->assignableSaleSellers($user, $record),
            'teams' => $this->assignableTeams($user),
            'canManageAssignment' => in_array($role, ['owner', 'admin', 'manager'], true),
            'canClearTeam' => in_array($role, ['owner', 'admin'], true),
            'assignmentHistory' => $assignmentHistory,
            'journeys' => $journeys,
            'availableJourneys' => $availableJourneys,
            'tasks' => $tasks,
            'sales' => $sales,
            'salesSummary' => app(SalesMetrics::class)->forCompany($record),
            'salesTimezone' => $salesTimezone,
            'customerScore' => $record->customerScore,
            'scoringWeights' => $commercialSettings->scoring_weights,
            'scoreHistory' => $scoreHistory,
        ]);
    }

    public function updateOwner(
        AssignCompanyRequest $request,
        int $company,
        CompanyAssignmentService $assignments,
    ): RedirectResponse {
        $record = Company::findOrFail($company);
        Gate::authorize('update', $record);

        $validated = $request->validated();
        $ownerId = array_key_exists('owner_user_id', $validated)
            ? ($validated['owner_user_id'] === null ? null : (int) $validated['owner_user_id'])
            : $record->owner_user_id;
        $teamId = array_key_exists('team_id', $validated)
            ? ($validated['team_id'] === null ? null : (int) $validated['team_id'])
            : $record->team_id;

        $assignments->assign($record, $ownerId, $teamId, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Carteira atualizada.');
    }

    public function storeActivity(StoreActivityRequest $request, int $company, CommercialInactivityService $inactivity): RedirectResponse
    {
        $record = Company::findOrFail($company);
        Gate::authorize('update', $record);

        $record->activities()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);
        $inactivity->resolveRelationshipForCompany($record);

        return redirect()->route('companies.show', $record->id)->with('success', 'Interação registrada no histórico.');
    }

    public function attachContact(Request $request, int $company): RedirectResponse
    {
        $record = Company::findOrFail($company);
        Gate::authorize('update', $record);

        $validated = $request->validate([
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where('organization_id', app(TenantContext::class)->id()),
            ],
            'roles' => ['required', 'array', 'min:1', 'max:5'],
            'roles.*' => ['required', 'string', 'distinct', 'in:owner,partner,budget_manager,buyer,decision_maker,influencer,specifier,finance,operations,other'],
            'is_primary' => ['sometimes', 'boolean'],
            'relationship_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact = Contact::findOrFail((int) $validated['contact_id']);
        Gate::authorize('view', $contact);

        DB::transaction(function () use ($record, $contact, $validated): void {
            if ($validated['is_primary'] ?? false) {
                DB::table('company_contacts')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->where('company_id', $record->id)
                    ->update(['is_primary' => false]);
            }

            $record->contacts()->syncWithoutDetaching([
                $contact->id => [
                    'organization_id' => app(TenantContext::class)->id(),
                    'roles' => $validated['roles'],
                    'is_primary' => $validated['is_primary'] ?? false,
                    'notes' => $validated['relationship_notes'] ?? null,
                ],
            ]);
        });

        return back()->with('success', 'Profissional vinculado à empresa.');
    }

    /** @return array<int, array{id: int, name: string, team_ids: array<int, int>}> */
    private function assignableMembers(User $user): array
    {
        $organization = app(TenantContext::class)->organization();
        if ($organization === null) {
            return [];
        }

        $members = $organization->users()
            ->where('organization_user.role', 'salesperson')
            ->where('organization_user.is_active', true)
            ->when($user->roleIn($organization->id) === 'manager', fn ($users) => $users->whereIn(
                'users.id',
                app(CommercialAccessScope::class)->managedSellerIds($user, $organization->id),
            ))
            ->when($user->roleIn($organization->id) === 'salesperson', fn ($users) => $users->where('users.id', $user->id))
            ->orderBy('name')
            ->get(['users.id', 'users.name']);

        $memberTeamIds = DB::table('team_memberships')
            ->where('organization_id', $organization->id)
            ->whereIn('user_id', $members->modelKeys())
            ->where('role', 'salesperson')
            ->where('is_active', true)
            ->get(['user_id', 'team_id'])
            ->groupBy('user_id');

        return $members->map(fn (User $member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'team_ids' => $memberTeamIds->get($member->id, collect())->pluck('team_id')->map(fn ($id): int => (int) $id)->all(),
        ])->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function assignableTeams(User $user): array
    {
        $organization = app(TenantContext::class)->organization();
        if ($organization === null) {
            return [];
        }

        return $organization->teams()
            ->where('status', 'active')
            ->when($user->roleIn($organization->id) === 'manager', fn ($teams) => $teams->whereIn(
                'teams.id',
                app(CommercialAccessScope::class)->managedTeamIds($user, $organization->id),
            ))
            ->when($user->roleIn($organization->id) === 'salesperson', fn ($teams) => $teams->whereHas('users', fn ($users) => $users
                ->where('users.id', $user->id)
                ->where('team_memberships.role', 'salesperson')
                ->where('team_memberships.is_active', true)))
            ->orderBy('name')
            ->get(['teams.id', 'teams.name'])
            ->map(fn ($team): array => ['id' => $team->id, 'name' => $team->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function assignableSaleSellers(User $user, Company $company): array
    {
        $organization = app(TenantContext::class)->organization();
        if ($organization === null) {
            return [];
        }

        return $organization->users()
            ->where('organization_user.role', 'salesperson')
            ->where('organization_user.is_active', true)
            ->when($company->team_id !== null, fn ($users) => $users->whereExists(fn ($teams) => $teams
                ->selectRaw('1')
                ->from('team_memberships')
                ->whereColumn('team_memberships.user_id', 'users.id')
                ->where('team_memberships.organization_id', $organization->id)
                ->where('team_memberships.team_id', $company->team_id)
                ->where('team_memberships.role', 'salesperson')
                ->where('team_memberships.is_active', true)))
            ->when($user->roleIn($organization->id) === 'salesperson', fn ($users) => $users->where('users.id', $user->id))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name])
            ->all();
    }
}
