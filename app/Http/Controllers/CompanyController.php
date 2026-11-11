<?php

namespace App\Http\Controllers;

use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Enums\OrganizationRole;
use App\Http\Requests\StoreActivityRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JourneyEnrollment;
use App\Models\JourneyTemplate;
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
            'statuses' => [
                ['value' => 'prospect', 'label' => 'Prospecto'],
                ['value' => 'customer', 'label' => 'Cliente'],
                ['value' => 'inactive', 'label' => 'Inativo'],
                ['value' => 'partner', 'label' => 'Parceiro'],
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request, JourneyService $journeys): RedirectResponse
    {
        $attributes = $request->validated();
        $user = $request->user();

        if ($user->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value) {
            $attributes['owner_user_id'] = $user->id;
        }

        $company = DB::transaction(function () use ($attributes, $user, $journeys): Company {
            $company = Company::create($attributes);
            $journeys->enrollDefaultForProspect($company, $user);

            return $company;
        });

        return redirect()->route('companies.show', $company->id)->with('success', 'Empresa cadastrada.');
    }

    public function show(Request $request, int $company): Response
    {
        $record = Company::query()
            ->with(['owner:id,name', 'contacts', 'activities' => fn ($query) => $query
                ->with(['contact:id,name', 'user:id,name'])
                ->limit(20)])
            ->findOrFail($company);

        Gate::authorize('view', $record);

        $user = $request->user();
        $attachableContacts = Contact::query()
            ->when($user->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value, fn ($query) => $query->whereHas(
                'companies', fn ($companies) => $companies->where('companies.owner_user_id', $user->id),
            ))
            ->whereDoesntHave('companies', fn ($companies) => $companies->where('companies.id', $record->id))
            ->orderBy('name')
            ->get(['id', 'name', 'job_title']);

        $journeys = JourneyEnrollment::query()
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
            ->where('company_id', $record->id)
            ->with(['contact:id,name', 'assignee:id,name', 'journeyEnrollment.template:id,name'])
            ->orderBy('due_at')
            ->limit(20)
            ->get();

        return Inertia::render('companies/show', [
            'company' => $record,
            'attachableContacts' => $attachableContacts,
            'members' => $this->assignableMembers($user),
            'journeys' => $journeys,
            'availableJourneys' => $availableJourneys,
            'tasks' => $tasks,
        ]);
    }

    public function updateOwner(Request $request, int $company, JourneyService $journeys): RedirectResponse
    {
        $record = Company::findOrFail($company);
        Gate::authorize('update', $record);

        $ownerRule = [
            'nullable',
            'integer',
            Rule::exists('organization_user', 'user_id')->where('organization_id', app(TenantContext::class)->id()),
        ];

        if ($request->user()->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value) {
            $ownerRule = ['required', 'integer', Rule::in([$request->user()->id]), ...$ownerRule];
        }

        $validated = $request->validate(['owner_user_id' => $ownerRule]);

        DB::transaction(function () use ($record, $validated, $request, $journeys): void {
            $locked = Company::query()->lockForUpdate()->findOrFail($record->id);
            $newOwnerId = isset($validated['owner_user_id']) ? (int) $validated['owner_user_id'] : null;
            $ownerChanged = $locked->owner_user_id !== $newOwnerId;
            $locked->update(['owner_user_id' => $newOwnerId]);

            if ($ownerChanged) {
                $journeys->reassignOpenTasks($locked, $newOwnerId, $request->user());
            }
        });

        return back()->with('success', 'Responsável atualizado.');
    }

    public function storeActivity(StoreActivityRequest $request, int $company): RedirectResponse
    {
        $record = Company::findOrFail($company);
        Gate::authorize('update', $record);

        $record->activities()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

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

    /** @return array<int, array{id: int, name: string}> */
    private function assignableMembers(User $user): array
    {
        $members = app(TenantContext::class)->organization()->users()->orderBy('name')->get(['users.id', 'users.name']);

        if ($user->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value) {
            $members = $members->where('id', $user->id)->values();
        }

        return $members->map(fn (User $member): array => [
            'id' => $member->id,
            'name' => $member->name,
        ])->all();
    }
}
