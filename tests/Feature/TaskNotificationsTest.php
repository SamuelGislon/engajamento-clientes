<?php

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

it('shows only notifications belonging to the active organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $otherOrganization->users()->attach($seller, ['role' => 'salesperson']);

    $seller->notify(new TaskEventNotification(
        'task_assigned', $organization->id, 'Tarefa da empresa', 'Tarefa atribuída.', taskId: 1,
    ));
    $seller->notify(new TaskEventNotification(
        'task_assigned', $otherOrganization->id, 'Outra tarefa', 'Tarefa de outra organização.', taskId: 2,
    ));

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('notifications.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.data.title', 'Tarefa da empresa')
            ->where('unreadCount', 1),
        );
});

it('does not open a notification from another organization by guessing its URL', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $otherOrganization->users()->attach($seller, ['role' => 'salesperson']);
    $notification = new TaskEventNotification(
        'task_assigned', $otherOrganization->id, 'Tarefa privada', 'Tarefa de outro tenant.', taskId: 999,
    );
    $seller->notify($notification);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('notifications.open', $seller->notifications()->firstOrFail()->id));

    $response->assertNotFound();
});

it('opens an authorized task notification and marks it read', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    $task = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
    ]);
    $seller->notify(new TaskEventNotification(
        'task_assigned', $organization->id, 'Nova atividade', 'Tarefa atribuída.', taskId: $task->id,
    ));
    $notification = $seller->notifications()->firstOrFail();

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('notifications.open', $notification->id));

    $response->assertRedirect(route('tasks.show', $task));
    expect($notification->fresh()->read_at)->not->toBeNull();
    $this->withoutVite()->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('tasks/show')->where('task.id', $task->id));
});

it('creates each due-window notification once when the scheduler is rerun', function () {
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    $soonTask = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'due_at' => now()->addHours(20),
    ]);
    $overdueTask = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'due_at' => now()->subMinute(),
    ]);
    Mail::fake();

    $this->artisan('engagement:notify-task-deadlines')->assertSuccessful();
    $this->artisan('engagement:notify-task-deadlines')->assertSuccessful();

    expect($seller->notifications()->where('data->event', 'task_due_soon')->count())->toBe(1)
        ->and($seller->notifications()->where('data->event', 'task_overdue')->count())->toBe(1);
    expect($soonTask->fresh()->due_soon_notified_at)->not->toBeNull()
        ->and($overdueTask->fresh()->overdue_notified_at)->not->toBeNull();
    Mail::assertNothingQueued();
});
