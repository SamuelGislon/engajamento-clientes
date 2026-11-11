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

        $organizations = $user->organizations()->orderBy('name')->get();

        if ($organizations->isEmpty()) {
            abort(403, 'Sua conta ainda não pertence a uma organização.');
        }

        $organization = $organizations->firstWhere(
            'id',
            (int) $request->session()->get('active_organization_id'),
        ) ?? $organizations->first();

        $request->session()->put('active_organization_id', $organization->id);
        app(TenantContext::class)->set($organization);

        return $next($request);
    }
}
