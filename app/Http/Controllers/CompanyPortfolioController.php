<?php

namespace App\Http\Controllers;

use App\Domain\Teams\CompanyAssignmentService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\BatchAssignCompaniesRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyPortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        abort_unless(in_array($request->user()->roleIn($organization->id), ['owner', 'admin'], true), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('organization_user', 'user_id')
                ->where('organization_id', $organization->id)->where('role', 'salesperson')->where('is_active', true)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')
                ->where('organization_id', $organization->id)->where('status', 'active')],
            'unassigned' => ['nullable', 'boolean'],
        ]);

        $sellers = $organization->users()
            ->where('organization_user.role', 'salesperson')
            ->where('organization_user.is_active', true)
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
        $teamsBySeller = DB::table('team_memberships')
            ->where('organization_id', $organization->id)
            ->whereIn('user_id', $sellers->modelKeys())
            ->where('role', 'salesperson')
            ->where('is_active', true)
            ->get(['user_id', 'team_id'])
            ->groupBy('user_id');

        return Inertia::render('companies/portfolio', [
            'companies' => Company::query()
                ->with(['owner:id,name', 'team:id,name'])
                ->when($filters['q'] ?? null, function ($query, string $search): void {
                    $term = '%'.$search.'%';
                    $query->where(fn ($nested) => $nested
                        ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(trade_name) LIKE LOWER(?)', [$term]));
                })
                ->when($filters['owner_user_id'] ?? null, fn ($query, int $ownerId) => $query->where('owner_user_id', $ownerId))
                ->when($filters['team_id'] ?? null, fn ($query, int $teamId) => $query->where('team_id', $teamId))
                ->when($request->boolean('unassigned'), fn ($query) => $query->whereNull('owner_user_id'))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'sellers' => $sellers->map(fn ($seller): array => [
                'id' => $seller->id,
                'name' => $seller->name,
                'team_ids' => $teamsBySeller->get($seller->id, collect())
                    ->pluck('team_id')->map(fn ($teamId): int => (int) $teamId)->all(),
            ]),
            'teams' => $organization->teams()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function transfer(BatchAssignCompaniesRequest $request, CompanyAssignmentService $assignments): RedirectResponse
    {
        $validated = $request->validated();
        $assignments->assignMany(
            $validated['company_ids'],
            isset($validated['owner_user_id']) ? (int) $validated['owner_user_id'] : null,
            isset($validated['team_id']) ? (int) $validated['team_id'] : null,
            $request->user(),
            $validated['reason'] ?? null,
        );

        return redirect()->route('companies.portfolio.index')->with('success', 'A carteira selecionada foi atualizada.');
    }
}
