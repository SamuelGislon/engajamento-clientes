<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = app(TenantContext::class)->id();
        abort_if($organizationId === null, 403);
        $notifications = $request->user()->notifications()
            ->where('data->organization_id', $organizationId)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()
                ->where('data->organization_id', $organizationId)
                ->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $this->findCurrentOrganizationNotification($request, $notification);
        $data = $record->data;

        if (! empty($data['task_id'])) {
            $task = Task::query()->findOrFail($data['task_id']);
            Gate::authorize('view', $task);
            $record->markAsRead();

            return redirect()->route('tasks.show', $task);
        }

        if (! empty($data['company_id'])) {
            $company = Company::query()->findOrFail($data['company_id']);
            Gate::authorize('view', $company);
            $record->markAsRead();

            return redirect()->route('companies.show', $company);
        }

        abort(404);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->findCurrentOrganizationNotification($request, $notification)->markAsRead();

        return back();
    }

    private function findCurrentOrganizationNotification(Request $request, string $notification): DatabaseNotification
    {
        $organizationId = app(TenantContext::class)->id();
        abort_if($organizationId === null, 403);

        return $request->user()->notifications()
            ->whereKey($notification)
            ->where('data->organization_id', $organizationId)
            ->firstOrFail();
    }
}
