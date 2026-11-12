<?php

use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Mail\TaskAssignedMail;
use App\Models\Company;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

it('persists organization-scoped journey templates and ordered steps', function () {
    expect(Schema::hasTable('journey_templates'))->toBeTrue()
        ->and(Schema::hasTable('journey_steps'))->toBeTrue()
        ->and(Schema::hasTable('journey_enrollments'))->toBeTrue()
        ->and(Schema::hasTable('journey_step_executions'))->toBeTrue();
});

it('creates reactivation and post-sale templates with their commercial triggers', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $this->actingAs($manager)->withSession(['active_organization_id' => $organization->id]);

    foreach ([
        ['Reativação Comercial', 'reactivation', 'sales_inactivity'],
        ['Pós-venda', 'post_sale', 'first_sale'],
    ] as [$name, $type, $trigger]) {
        $this->post(route('journeys.templates.store'), [
            'name' => $name,
            'description' => 'Modelo comercial configurável.',
            'is_active' => true,
            'is_default' => false,
            'type' => $type,
            'trigger_type' => $trigger,
            'steps' => [[
                'title' => 'Primeiro acompanhamento',
                'description' => 'Contato comercial.',
                'delay_days' => 1,
                'task_title' => 'Acompanhar {company}',
                'task_description' => null,
                'task_type' => 'follow_up',
                'task_priority' => 'normal',
            ]],
        ])->assertRedirect(route('journeys.index'));
    }

    expect(JourneyTemplate::query()->where('type', 'reactivation')->where('trigger_type', 'sales_inactivity')->exists())->toBeTrue()
        ->and(JourneyTemplate::query()->where('type', 'post_sale')->where('trigger_type', 'first_sale')->exists())->toBeTrue();
});

it('enrolls a new prospect in the active default journey and creates its first task', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $templateId = DB::table('journey_templates')->insertGetId([
        'organization_id' => $organization->id,
        'name' => 'Prospecção Comercial',
        'description' => 'Jornada padrão de novos prospectos.',
        'type' => 'prospecting',
        'trigger_type' => 'company_created',
        'is_active' => true,
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journey_steps')->insert([
        'organization_id' => $organization->id,
        'journey_template_id' => $templateId,
        'title' => 'Primeiro contato',
        'position' => 1,
        'delay_days' => 1,
        'task_title' => 'Realizar primeiro contato com {company}.',
        'task_type' => 'first_contact',
        'task_priority' => 'normal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('companies.store'), [
            'name' => 'Escritório Horizonte',
            'relationship_status' => 'prospect',
        ]);

    $company = DB::table('companies')->where('name', 'Escritório Horizonte')->first();
    expect(DB::table('journey_enrollments')->where('company_id', $company->id)->count())->toBe(1);
    $enrollment = DB::table('journey_enrollments')->where('company_id', $company->id)->first();
    $task = Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail();
    $response->assertRedirect(route('companies.show', $company->id));
    expect($task->title)->toBe('Realizar primeiro contato com Escritório Horizonte.')
        ->and($task->assigned_to)->toBe($seller->id)
        ->and($task->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-12 15:00:00')
        ->and($task->origin)->toBe('automatic');
});

it('starts an existing prospect in a selected journey manually', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $templateId = DB::table('journey_templates')->insertGetId([
        'organization_id' => $organization->id,
        'name' => 'Prospecção Especializada',
        'type' => 'prospecting',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journey_steps')->insert([
        'organization_id' => $organization->id,
        'journey_template_id' => $templateId,
        'title' => 'Apresentação',
        'position' => 1,
        'delay_days' => 2,
        'task_title' => 'Apresentar soluções para {company}.',
        'task_type' => 'meeting',
        'task_priority' => 'high',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('companies.journeys.store', $company), ['journey_template_id' => $templateId]);
    $this->post(route('companies.journeys.store', $company), ['journey_template_id' => $templateId]);

    $enrollment = DB::table('journey_enrollments')->where('company_id', $company->id)->first();
    expect(DB::table('journey_enrollments')->where('company_id', $company->id)->count())->toBe(1);
    $task = Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail();
    $response->assertRedirect(route('companies.show', $company->id));
    expect($enrollment->status)->toBe('active')
        ->and($task->assigned_to)->toBe($manager->id)
        ->and($task->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-13 15:00:00')
        ->and($task->title)->toBe('Apresentar soluções para '.$company->name.'.');
});

it('creates the next journey task from the previous task completion date', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $seller);
    $firstTask = Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail();
    $this->travelTo('2026-11-11 16:00:00');

    $response = $this->actingAs($seller)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('tasks.complete', $firstTask), ['completion_notes' => 'Apresentação realizada.']);

    $tasks = Task::query()->where('journey_enrollment_id', $enrollment->id)->orderBy('id')->get();
    $response->assertRedirect(route('tasks.index'));
    expect($tasks)->toHaveCount(2)
        ->and($tasks[1]->title)->toBe('Fazer acompanhamento comercial com '.$company->name.'.')
        ->and($tasks[1]->due_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-18 16:00:00');
});

it('keeps completed steps while paused and resumes at the next step', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $seller);
    $firstTask = Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail();
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);

    $this->post(route('journeys.enrollments.pause', $enrollment))->assertRedirect();
    $this->travelTo('2026-11-11 16:00:00');
    $this->post(route('tasks.complete', $firstTask), [])->assertRedirect(route('tasks.index'));
    expect($enrollment->fresh()->status)->toBe('paused')
        ->and(Task::query()->where('journey_enrollment_id', $enrollment->id)->count())->toBe(1);

    $response = $this->post(route('journeys.enrollments.resume', $enrollment));

    $response->assertRedirect();
    expect($enrollment->fresh()->status)->toBe('active')
        ->and(Task::query()->where('journey_enrollment_id', $enrollment->id)->count())->toBe(2);
});

it('cancels open automatic tasks and retains manual tasks and execution history', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $manager);
    $manualTask = Task::query()->create([
        'company_id' => $company->id,
        'assigned_to' => $manager->id,
        'created_by' => $manager->id,
        'title' => 'Ação manual independente',
        'type' => 'custom',
        'priority' => 'normal',
        'status' => 'pending',
        'due_at' => now()->addDay(),
        'origin' => 'manual',
    ]);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('journeys.enrollments.cancel', $enrollment));

    $response->assertRedirect();
    expect($enrollment->fresh()->status)->toBe('cancelled')
        ->and($manualTask->fresh()->status)->toBe('pending')
        ->and($enrollment->executions()->count())->toBe(1);
    expect(Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail()->status)->toBe('cancelled');
});

it('creates a template with ordered configurable journey steps', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('journeys.templates.store'), [
            'name' => 'Relacionamento com escritórios',
            'description' => 'Primeiros contatos e retornos.',
            'is_active' => true,
            'is_default' => false,
            'steps' => [
                [
                    'title' => 'Apresentação',
                    'description' => 'Conhecer o escritório.',
                    'delay_days' => 2,
                    'task_title' => 'Apresentar a empresa para {company}.',
                    'task_type' => 'meeting',
                    'task_priority' => 'high',
                ],
                [
                    'title' => 'Retorno',
                    'description' => 'Acompanhar a conversa.',
                    'delay_days' => 5,
                    'task_title' => 'Retornar para {company}.',
                    'task_type' => 'follow_up',
                    'task_priority' => 'normal',
                ],
            ],
        ]);

    $template = JourneyTemplate::query()->where('name', 'Relacionamento com escritórios')->firstOrFail();
    $response->assertRedirect(route('journeys.index'));
    expect($template->steps()->orderBy('position')->pluck('delay_days')->all())->toBe([2, 5]);
});

it('lets an organization manager disable automatic default journey enrollment', function () {
    $organization = Organization::factory()->create(['auto_enroll_default_journey' => true]);
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->from(route('journeys.index'))
        ->patch(route('journeys.settings.update'), ['auto_enroll_default_journey' => false]);

    $response->assertRedirect(route('journeys.index'));
    expect($organization->fresh()->auto_enroll_default_journey)->toBeFalse();
});

it('renders organization templates and journey settings for managers', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    app(JourneyService::class)->ensureDefaultTemplate($organization);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('journeys.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('journeys/index')
            ->has('templates.data', 1)
            ->where('autoEnrollDefaultJourney', true)
            ->where('canManage', true),
        );
});

it('includes active journeys and tasks on the authorized company page', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $manager);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->withoutVite()
        ->get(route('companies.show', $company));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/show')
            ->has('journeys', 1)
            ->where('journeys.0.id', $enrollment->id)
            ->has('journeys.0.tasks', 1)
            ->has('availableJourneys', 1),
        );
});

it('reassigns open automatic journey tasks when a company owner changes', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $previousOwner = User::factory()->create();
    $newOwner = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($previousOwner, ['role' => 'salesperson']);
    $organization->users()->attach($newOwner, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    assignTeamMember($organization, $team, $previousOwner, 'salesperson');
    assignTeamMember($organization, $team, $newOwner, 'salesperson');
    Mail::fake();
    $company = Company::factory()->ownedBy($previousOwner)->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $previousOwner);
    $automaticTask = Task::query()->where('journey_enrollment_id', $enrollment->id)->firstOrFail();
    $manualTask = Task::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $company->id,
        'assigned_to' => $previousOwner->id,
        'created_by' => $manager->id,
        'origin' => 'manual',
    ]);

    $response = $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->patch(route('companies.owner.update', $company), ['owner_user_id' => $newOwner->id]);

    $response->assertRedirect();
    expect($company->fresh()->owner_user_id)->toBe($newOwner->id)
        ->and($enrollment->fresh()->assigned_to)->toBe($newOwner->id)
        ->and($automaticTask->fresh()->assigned_to)->toBe($newOwner->id)
        ->and($manualTask->fresh()->assigned_to)->toBe($previousOwner->id);
    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($newOwner->email));
    expect($newOwner->notifications()->where('data->event', 'task_assigned')->count())->toBe(1);
});

it('does not auto-enroll prospects when the organization turns the setting off', function () {
    $organization = Organization::factory()->create(['auto_enroll_default_journey' => false]);
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    app(JourneyService::class)->ensureDefaultTemplate($organization);

    $this->actingAs($manager)
        ->withSession(['active_organization_id' => $organization->id])
        ->post(route('companies.store'), [
            'name' => 'Escritório Sem Jornada',
            'relationship_status' => 'prospect',
            'team_id' => $team->id,
        ]);

    $company = Company::query()->where('name', 'Escritório Sem Jornada')->firstOrFail();
    expect(DB::table('journey_enrollments')->where('company_id', $company->id)->count())->toBe(0)
        ->and(Task::query()->where('company_id', $company->id)->count())->toBe(0);
});

it('notifies once when the full journey is completed', function () {
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    app(TenantContext::class)->set($organization);
    $team = createManagedTeam($organization, $manager);
    $company = Company::factory()->create([
        'organization_id' => $organization->id,
        'team_id' => $team->id,
        'relationship_status' => 'prospect',
    ]);
    $template = app(JourneyService::class)->ensureDefaultTemplate($organization);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $manager);
    Mail::fake();
    $this->actingAs($manager)->withSession(['active_organization_id' => $organization->id]);

    for ($step = 0; $step < 4; $step++) {
        $task = $enrollment->tasks()->whereIn('status', ['pending', 'in_progress', 'postponed'])->firstOrFail();
        $this->post(route('tasks.complete', $task), [])->assertRedirect(route('tasks.index'));
    }

    expect($enrollment->fresh()->status)->toBe('completed')
        ->and($manager->notifications()->where('data->event', 'journey_completed')->count())->toBe(1);
    $lastTask = $enrollment->tasks()->where('status', 'completed')->latest('completed_at')->firstOrFail();
    app(JourneyService::class)->completeTask($lastTask, $manager, null);
    expect($manager->notifications()->where('data->event', 'journey_completed')->count())->toBe(1);
});
