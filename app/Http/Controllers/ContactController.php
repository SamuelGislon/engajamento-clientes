<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Enums\ContactRole;
use App\Enums\OrganizationRole;
use App\Http\Requests\StoreContactRequest;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $user = $request->user();
        $isSalesperson = $user->roleIn(app(TenantContext::class)->id()) === OrganizationRole::Salesperson->value;
        $contacts = Contact::query()
            ->when($isSalesperson, fn ($query) => $query->whereHas(
                'companies', fn ($companies) => $companies->where('companies.owner_user_id', $user->id),
            ))
            ->with(['companies' => function (BelongsToMany $query) use ($isSalesperson, $user): void {
                if ($isSalesperson) {
                    $query->where('companies.owner_user_id', $user->id);
                }

                $query->select('companies.id', 'companies.name', 'companies.trade_name');
            }])
            ->when($request->string('q')->trim()->isNotEmpty(), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($nested) => $nested
                    ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('contacts/index', [
            'contacts' => $contacts,
            'filters' => $request->only('q'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Contact::class);

        $user = $request->user();
        $companies = Company::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name', 'trade_name']);

        return Inertia::render('contacts/create', [
            'companies' => $companies,
            'selectedCompanyId' => $request->integer('company_id') ?: null,
            'roles' => collect(ContactRole::cases())->map(fn ($role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])->all(),
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $company = Company::query()->visibleTo($request->user())->findOrFail((int) $validated['company_id']);
        $isPrimary = $validated['is_primary'] ?? false;

        DB::transaction(function () use ($validated, $company, $isPrimary): void {
            if ($isPrimary) {
                DB::table('company_contacts')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->where('company_id', $company->id)
                    ->update(['is_primary' => false]);
            }

            $contact = Contact::create(collect($validated)->only([
                'name', 'email', 'phone', 'whatsapp', 'job_title', 'preferred_channel',
                'contact_preferences', 'professional_interests', 'notes',
            ])->all());

            $company->contacts()->attach($contact->id, [
                'organization_id' => app(TenantContext::class)->id(),
                'roles' => $validated['roles'],
                'is_primary' => $isPrimary,
                'notes' => $validated['relationship_notes'] ?? null,
            ]);
        });

        return redirect()->route('companies.show', $company->id)->with('success', 'Profissional cadastrado e vinculado.');
    }
}
