<?php

use App\Domain\Tenancy\TenantContext;
use App\Mail\TaskAssignedMail;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

it('creates a manual task for a company in the salesperson portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Ligar para confirmar interesse',
            'description' => 'Apresentar os acabamentos disponíveis.',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);

    $task = Task::query()->firstOrFail();
    $response->assertRedirect(route('tasks.index'));
    expect($task->organization_id)->toBe($organization->id)
        ->and($task->company_id)->toBe($company->id)
        ->and($task->assigned_to)->toBe($seller->id)
        ->and($task->created_by)->toBe($seller->id)
        ->and($task->status)->toBe('pending')
        ->and($task->origin)->toBe('manual');
});

it('completes a task without recording a fictitious interaction', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Confirmar os próximos passos',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $task = Task::query()->firstOrFail();

    $response = $this->post(route('tasks.complete', $task), [
        'completion_notes' => 'O escritório pediu retorno na próxima semana.',
    ]);

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->completed_by)->toBe($seller->id)
        ->and($task->fresh()->completion_notes)->toBe('O escritório pediu retorno na próxima semana.');
    expect(Activity::query()->count())->toBe(0);
});

it('selects the active organization before resolving a task route binding', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $task = Task::factory()->create([
        'organization_id' => $organization->id,
        'assigned_to' => $seller->id,
        'created_by' => $seller->id,
        'title' => 'Concluir tarefa após resolver o tenant',
    ]);

    app()->instance(TenantContext::class, new TenantContext);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.complete', $task));

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->status)->toBe('completed');
});

it('rejects a company from another organization when creating a task', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $otherSeller = User::factory()->create();
    $otherOrganization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($otherOrganization);
    $foreignCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $otherOrganization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from(route('tasks.index'))
        ->post(route('tasks.store'), [
            'company_id' => $foreignCompany->id,
            'title' => 'Tarefa inválida',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);

    $response->assertSessionHasErrors('company_id');
    $this->assertDatabaseMissing('tasks', ['title' => 'Tarefa inválida']);
});

it('rejects a salesperson task linked to another salesperson portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from(route('tasks.index'))
        ->post(route('tasks.store'), [
            'company_id' => $otherCompany->id,
            'title' => 'Acesso indevido',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);

    $response->assertSessionHasErrors('company_id');
    $this->assertDatabaseMissing('tasks', ['title' => 'Acesso indevido']);
});

it('rejects an assignee from another organization and a contact not linked to the chosen company', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $seller = User::factory()->create();
    $foreignSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'manager']);
    $foreignOrganization->users()->attach($foreignSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->create(['organization_id' => $organization->id]);
    $otherCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);
    $otherCompany->contacts()->attach($contact->id, [
        'organization_id' => $organization->id,
        'roles' => ['buyer'],
        'is_primary' => false,
    ]);
    $payload = [
        'company_id' => $company->id,
        'title' => 'Validar vínculos comerciais',
        'type' => 'call',
        'priority' => 'normal',
        'due_at' => now()->addDay()->toISOString(),
    ];

    $assigneeResponse = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->from(route('tasks.create'))
        ->post(route('tasks.store'), [...$payload, 'assigned_to' => $foreignSeller->id]);
    $assigneeResponse->assertSessionHasErrors('assigned_to');

    $contactResponse = $this->post(route('tasks.store'), [...$payload, 'contact_id' => $contact->id]);

    $contactResponse->assertSessionHasErrors('contact_id');
    $this->assertDatabaseMissing('tasks', ['title' => 'Validar vínculos comerciais']);
});

it('limits a salesperson task list to their assigned portfolio', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $sellerCompany = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);
    $otherCompany = Company::factory()->ownedBy($otherSeller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($otherSeller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $otherCompany->id,
            'title' => 'Tarefa de outro vendedor',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $sellerCompany->id,
            'title' => 'Minha tarefa',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);

    $response = $this->withoutVite()->get(route('tasks.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('tasks/index')
            ->has('tasks.data', 1)
            ->where('tasks.data.0.title', 'Minha tarefa'),
        );
});

it('limits a manager to tasks in the teams they manage', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $firstSeller = User::factory()->create();
    $secondSeller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($firstSeller, ['role' => 'salesperson']);
    $organization->users()->attach($secondSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager, 'Equipe Gerenciada');
    assignTeamMember($organization, $team, $firstSeller, 'salesperson');
    $otherTeam = $organization->teams()->create(['name' => 'Equipe Externa', 'created_by' => $manager->id]);
    assignTeamMember($organization, $otherTeam, $secondSeller, 'salesperson');
    $firstCompany = Company::factory()->ownedBy($firstSeller)->create(['organization_id' => $organization->id, 'team_id' => $team->id]);
    $secondCompany = Company::factory()->ownedBy($secondSeller)->create(['organization_id' => $organization->id, 'team_id' => $otherTeam->id]);
    foreach ([[$firstSeller, $firstCompany, 'Tarefa da equipe'], [$secondSeller, $secondCompany, 'Tarefa externa']] as [$assignee, $company, $title]) {
        Task::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'assigned_to' => $assignee->id,
            'created_by' => $manager->id,
            'title' => $title,
        ]);
    }

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('tasks.index'));

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('tasks/index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.title', 'Tarefa da equipe'));
});

it('postpones an open task to a new due date', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Acompanhar proposta',
            'type' => 'follow_up',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $task = Task::query()->firstOrFail();
    $newDueAt = now()->addDays(4)->startOfMinute();

    $response = $this->patch(route('tasks.postpone', $task), ['due_at' => $newDueAt->toISOString()]);

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->status)->toBe('postponed')
        ->and($task->fresh()->due_at->timestamp)->toBe($newDueAt->timestamp);
});

it('cancels an open task without deleting its history', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Retornar para o cliente',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $task = Task::query()->firstOrFail();

    $response = $this->post(route('tasks.cancel', $task));

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->status)->toBe('cancelled');
    $this->assertModelExists($task->fresh());
});

it('updates an authorized manual task', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Contato inicial',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $task = Task::query()->firstOrFail();

    $response = $this->patch(route('tasks.update', $task), [
        'title' => 'Contato inicial atualizado',
        'priority' => 'high',
    ]);

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->title)->toBe('Contato inicial atualizado')
        ->and($task->fresh()->priority)->toBe('high');
});

it('accepts a revised due date and resets reminder markers', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    assignTeamMember($organization, $team, $seller, 'salesperson');
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
    ]);
    $task = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $seller->id,
        'created_by' => $manager->id,
        'due_at' => now()->addDay(),
        'due_soon_notified_at' => now(),
        'overdue_notified_at' => now(),
    ]);
    $newDueAt = now()->addDays(5)->startOfMinute();

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('tasks.update', $task), ['due_at' => $newDueAt->toISOString()]);

    $response->assertRedirect(route('tasks.index'));
    expect($task->fresh()->due_at->timestamp)->toBe($newDueAt->timestamp)
        ->and($task->fresh()->due_soon_notified_at)->toBeNull()
        ->and($task->fresh()->overdue_notified_at)->toBeNull();
});

it('filters the task list by status and priority on the server', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Urgente hoje',
            'type' => 'call',
            'priority' => 'urgent',
            'due_at' => now()->addDay()->toISOString(),
        ]);
    $this->post(route('tasks.store'), [
        'company_id' => $company->id,
        'title' => 'Acompanhamento normal',
        'type' => 'follow_up',
        'priority' => 'normal',
        'due_at' => now()->addDay()->toISOString(),
    ]);

    $response = $this->withoutVite()->get(route('tasks.index', ['status' => 'pending', 'priority' => 'urgent']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('tasks/index')
            ->has('tasks.data', 1)
            ->where('tasks.data.0.title', 'Urgente hoje')
            ->where('filters.priority', 'urgent'),
        );
});

it('loads only the salesperson companies and members on the task form', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $otherSeller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    $organization->users()->attach($otherSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    Company::factory()->ownedBy($otherSeller)->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('tasks.create'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('tasks/form')
            ->has('companies', 1)
            ->where('companies.0.id', $company->id)
            ->has('members', 1)
            ->where('members.0.id', $seller->id),
        );
});

it('notifies the assigned seller when a manual task is created', function () {
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create(['organization_id' => $organization->id]);
    Notification::fake();
    Mail::fake();

    $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.store'), [
            'company_id' => $company->id,
            'title' => 'Ligar amanhã',
            'type' => 'call',
            'priority' => 'normal',
            'due_at' => now()->addDay()->toISOString(),
        ]);

    Notification::assertSentTo($seller, TaskEventNotification::class, fn (TaskEventNotification $notification): bool => $notification->event === 'task_assigned');
    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($seller->email));
});

it('notifies a new assignee when a manual task is reassigned', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    assignTeamMember($organization, $team, $seller, 'salesperson');
    $company = Company::factory()->create(['organization_id' => $organization->id, 'team_id' => $team->id]);
    $task = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $manager->id,
        'created_by' => $manager->id,
    ]);
    Notification::fake();
    Mail::fake();

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('tasks.update', $task), ['assigned_to' => $seller->id]);

    Notification::assertSentTo($seller, TaskEventNotification::class, fn (TaskEventNotification $notification): bool => $notification->event === 'task_assigned');
    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($seller->email));
});
