<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $organizations = $user->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('name')
            ->get();

        if ($organizations->isEmpty()) {
            abort(403, 'Sua conta ainda não pertence a uma organização.');
        }

        $organization = $organizations->firstWhere(
            'id',
            (int) $request->session()->get('active_organization_id'),
        ) ?? $organizations->first();

        $request->session()->put('active_organization_id', $organization->id);
        app(TenantContext::class)->set($organization);

        if ($organization->onboarding_step !== null
            && $organization->onboarding_completed_at === null
            && $user->roleIn($organization->id) === 'owner') {
            $routeName = $request->route()?->getName() ?? '';
            $canUseOnboardingResources = str_starts_with($routeName, 'onboarding.')
                || str_starts_with($routeName, 'organization.users.')
                || str_starts_with($routeName, 'organization.invitations.')
                || str_starts_with($routeName, 'companies.')
                || str_starts_with($routeName, 'journeys.')
                || str_starts_with($routeName, 'teams.')
                || str_starts_with($routeName, 'imports.');

            if (! $canUseOnboardingResources) {
                return redirect()->route('onboarding.show');
            }
        }

        return $next($request);
    }
}
