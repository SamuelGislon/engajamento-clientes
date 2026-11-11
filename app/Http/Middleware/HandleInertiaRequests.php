<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'activeOrganization' => app(TenantContext::class)->organization()?->only(['id', 'name', 'timezone']),
            'organizations' => fn () => $request->user()?->organizations()
                ->orderBy('name')
                ->get(['organizations.id', 'organizations.name'])
                ->map(fn ($organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'role' => $organization->pivot->role,
                ]),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'notificationsUnreadCount' => fn () => $request->user() === null || app(TenantContext::class)->id() === null
                ? 0
                : $request->user()->unreadNotifications()
                    ->where('data->organization_id', app(TenantContext::class)->id())
                    ->count(),
        ];
    }
}
