<?php

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('creates one commercial reactivation task with a stable recommended contact and excludes prospects without purchases', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $inactiveCompany = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'name' => 'Escritório Horizonte',
        'relationship_status' => 'customer',
    ]);
    $prospect = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'prospect',
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Rafael',
        'job_title' => 'Responsável por orçamentos',
    ]);
    $inactiveCompany->contacts()->attach($contact, [
        'organization_id' => $organization->id,
        'roles' => ['budget_manager'],
        'is_primary' => false,
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$inactiveCompany->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra antiga',
        'amount' => '2500.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    Task::query()->where('company_id', $inactiveCompany->id)->update(['status' => 'completed']);
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $task = Task::query()->where('company_id', $inactiveCompany->id)->firstOrFail();
    expect($task->origin)->toBe('automatic')
        ->and($task->type)->toBe('reactivation')
        ->and($task->assigned_to)->toBe($seller->id)
        ->and($task->contact_id)->toBe($contact->id)
        ->and($task->title)->toContain('Escritório Horizonte')
        ->and($task->description)->toContain('100 dias sem uma compra confirmada')
        ->and($task->description)->toContain('Rafael')
        ->and(Task::query()->count())->toBe(1);
    expect(DB::table('commercial_alert_occurrences')->where('company_id', $inactiveCompany->id)->count())->toBe(1)
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $prospect->id)->count())->toBe(0);
});

it('uses organization thresholds and starts a new inactivity occurrence only after another purchase', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'sales_inactivity_days' => 120,
        'relationship_inactivity_days' => 365,
        'contact_frequency_days' => ['A' => 365, 'B' => 365, 'C' => 365, 'D' => 365],
    ]);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra antiga',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    expect(Task::query()->where('company_id', $company->id)->count())->toBe(0);

    $settings->update(['sales_inactivity_days' => 90]);
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $firstTask = Task::query()->where('company_id', $company->id)->firstOrFail();

    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Nova compra',
        'amount' => '250.00',
        'currency' => 'BRL',
        'sold_at' => now($organization->timezone)->toDateTimeString(),
    ])->assertRedirect();
    expect($firstTask->fresh()->status)->toBe('cancelled')
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->where('status', 'active')->count())->toBe(0);

    $this->travelTo(now()->addDays(91));
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    expect(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->where('type', 'commercial_inactivity')->count())->toBe(2)
        ->and(Task::query()->where('company_id', $company->id)->where('type', 'reactivation')->count())->toBe(2);
});

it('detects relationship inactivity separately from recent purchases', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra recente',
        'amount' => '500.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(5)->toDateTimeString(),
    ])->assertRedirect();
    app(TenantContext::class)->set($organization);
    Activity::factory()->forCompany($company, $seller)->create([
        'occurred_at' => now()->subDays(40),
        'description' => 'Última conversa comercial',
    ]);
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $task = Task::query()->where('company_id', $company->id)->firstOrFail();
    expect($task->type)->toBe('follow_up')
        ->and($task->description)->toContain('40 dias sem uma interação')
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->value('type'))
        ->toBe('relationship_inactivity');
});

it('repeats a completed reactivation action only after the organization cooldown', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $settings = app(CommercialSettingsService::class)->forOrganization($organization);
    $settings->update([
        'reactivation_repeat_cooldown_days' => 7,
        'relationship_inactivity_days' => 365,
        'contact_frequency_days' => ['A' => 365, 'B' => 365, 'C' => 365, 'D' => 365],
    ]);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra anterior',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(90)->toDateTimeString(),
    ])->assertRedirect();
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $firstTask = Task::query()->where('company_id', $company->id)->firstOrFail();
    $firstTask->update(['status' => 'completed']);
    $this->travel(6)->days();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    expect(Task::query()->where('company_id', $company->id)->count())->toBe(1);

    $this->travel(1)->day();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    expect(Task::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->where('type', 'commercial_inactivity')->count())->toBe(2);
});

it('enrolls an inactive customer into the configured reactivation journey instead of creating a parallel task', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra anterior',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    $template = JourneyTemplate::query()->create([
        'name' => 'Reativação Comercial',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    $template->steps()->create([
        'title' => 'Contato de reativação',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Retomar contato com {company}',
        'task_type' => 'reactivation',
        'task_priority' => 'high',
    ]);
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['reactivation_journey_template_id' => $template->id]);
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    expect($template->enrollments()->where('company_id', $company->id)->where('status', 'active')->exists())->toBeTrue()
        ->and(Task::query()->where('company_id', $company->id)->where('journey_enrollment_id', '!=', null)->count())->toBe(1)
        ->and(Task::query()->where('company_id', $company->id)->whereNull('journey_enrollment_id')->where('type', 'reactivation')->count())->toBe(0)
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->where('status', 'active')->value('task_id'))
        ->toBe(Task::query()->where('company_id', $company->id)->value('id'));
});

it('does not reuse an unrelated post-sale journey task for commercial reactivation', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra anterior',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    $postSaleTemplate = JourneyTemplate::query()->create([
        'name' => 'Acompanhamento pós-venda',
        'type' => 'post_sale',
        'trigger_type' => 'first_sale',
        'is_active' => true,
    ]);
    $postSaleTemplate->steps()->create([
        'title' => 'Acompanhamento pós-venda',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Acompanhar compra de {company}',
        'task_type' => 'follow_up',
        'task_priority' => 'normal',
    ]);
    $postSaleTask = app(JourneyService::class)->enroll($company, $postSaleTemplate, $seller)->tasks()->firstOrFail();
    $reactivationTemplate = JourneyTemplate::query()->create([
        'name' => 'Reativação Comercial',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    $reactivationTemplate->steps()->create([
        'title' => 'Contato de reativação',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Retomar contato com {company}',
        'task_type' => 'reactivation',
        'task_priority' => 'high',
    ]);
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['reactivation_journey_template_id' => $reactivationTemplate->id]);
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $occurrence = DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->firstOrFail();
    $reactivationTask = Task::query()->where('company_id', $company->id)
        ->whereHas('journeyEnrollment.template', fn ($query) => $query->where('type', 'reactivation'))
        ->firstOrFail();
    expect($occurrence->task_id)->toBe($reactivationTask->id)
        ->and($occurrence->task_id)->not->toBe($postSaleTask->id);
});

it('cancels a reactivation enrollment when its occurrence is resolved and allows a later recurrence', function (string $resolution) {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra antiga',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['relationship_inactivity_days' => 365]);
    $template = JourneyTemplate::query()->create([
        'name' => 'Reativação Comercial',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    $template->steps()->create([
        'title' => 'Contato de reativação',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Retomar contato com {company}',
        'task_type' => 'reactivation',
        'task_priority' => 'high',
    ]);
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['reactivation_journey_template_id' => $template->id]);
    Mail::fake();
    Notification::fake();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $enrollment = $template->enrollments()->where('company_id', $company->id)->firstOrFail();
    $execution = $enrollment->executions()->firstOrFail();
    $task = $enrollment->tasks()->firstOrFail();

    if ($resolution === 'new_sale') {
        $this->post("/companies/{$company->id}/sales", [
            'seller_id' => $seller->id,
            'description' => 'Nova compra',
            'amount' => '250.00',
            'currency' => 'BRL',
            'sold_at' => now($organization->timezone)->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    } else {
        $this->post(route('tasks.cancel', $task))->assertRedirect();
        $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    }

    expect($enrollment->fresh()->status)->toBe('cancelled')
        ->and($execution->fresh()->status)->toBe('cancelled')
        ->and($task->fresh()->status)->toBe('cancelled');

    $this->travel(100)->days();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $enrollments = $template->enrollments()->where('company_id', $company->id)->orderBy('id')->get();
    $nextEnrollment = $enrollments->last();

    expect($enrollments)->toHaveCount(2)
        ->and($nextEnrollment->status)->toBe('active')
        ->and($nextEnrollment->tasks()->whereIn('status', ['pending', 'in_progress', 'postponed'])->exists())->toBeTrue();
})->with([
    'new purchase' => 'new_sale',
    'cancelled task' => 'cancelled_task',
]);

it('does not reuse a future reactivation journey task for relationship inactivity', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra recente',
        'amount' => '500.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(5)->toDateTimeString(),
    ])->assertRedirect();
    $reactivationTemplate = JourneyTemplate::query()->create([
        'name' => 'Reativação futura',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    $reactivationTemplate->steps()->create([
        'title' => 'Contato de reativação',
        'position' => 1,
        'delay_days' => 30,
        'task_title' => 'Retomar contato com {company}',
        'task_type' => 'reactivation',
        'task_priority' => 'high',
    ]);
    $futureTask = app(JourneyService::class)->enroll($company, $reactivationTemplate, $seller)->tasks()->firstOrFail();
    Activity::factory()->forCompany($company, $seller)->create([
        'occurred_at' => now()->subDays(40),
        'description' => 'Última conversa comercial',
    ]);
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $occurrence = DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->firstOrFail();
    $relationshipTask = Task::query()->where('company_id', $company->id)
        ->whereNull('journey_enrollment_id')
        ->where('type', 'follow_up')
        ->firstOrFail();
    expect($occurrence->task_id)->toBe($relationshipTask->id)
        ->and($occurrence->task_id)->not->toBe($futureTask->id);
});

it('keeps an unrelated post-sale journey active when relationship inactivity is resolved', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra recente',
        'amount' => '500.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(5)->toDateTimeString(),
    ])->assertRedirect();
    Activity::factory()->forCompany($company, $seller)->create([
        'occurred_at' => now()->subDays(40),
        'description' => 'Último contato',
    ]);
    $template = JourneyTemplate::query()->create([
        'name' => 'Acompanhamento pós-venda',
        'type' => 'post_sale',
        'trigger_type' => 'first_sale',
        'is_active' => true,
    ]);
    $template->steps()->createMany([
        [
            'title' => 'Acompanhamento atual',
            'position' => 1,
            'delay_days' => 0,
            'task_title' => 'Acompanhar compra de {company}',
            'task_type' => 'follow_up',
            'task_priority' => 'normal',
        ],
        [
            'title' => 'Próximo acompanhamento',
            'position' => 2,
            'delay_days' => 30,
            'task_title' => 'Verificar resultado da compra de {company}',
            'task_type' => 'follow_up',
            'task_priority' => 'normal',
        ],
    ]);
    $enrollment = app(JourneyService::class)->enroll($company, $template, $seller);
    $execution = $enrollment->executions()->firstOrFail();
    $task = $enrollment->tasks()->firstOrFail();
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $occurrence = DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->firstOrFail();
    expect($occurrence->task_id)->toBe($task->id);

    $this->post(route('companies.activities.store', $company), [
        'type' => 'call',
        'occurred_at' => now()->toISOString(),
        'description' => 'Contato comercial realizado',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($enrollment->fresh()->status)->toBe('active')
        ->and($execution->fresh()->status)->toBe('active')
        ->and($task->fresh()->status)->toBe('pending');

    $this->post(route('tasks.complete', $task), [
        'completion_notes' => 'Acompanhamento concluído após interação.',
    ])->assertRedirect();

    expect($enrollment->fresh()->status)->toBe('active')
        ->and($enrollment->tasks()->where('title', 'Verificar resultado da compra de '.$company->name)->exists())->toBeTrue();
});

it('ends the reactivation journey when a purchase resolves commercial inactivity alongside a separate relationship alert', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $seller = User::factory()->create();
    $organization->users()->attach($seller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($seller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($seller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Compra antiga',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    Activity::factory()->forCompany($company, $seller)->create([
        'occurred_at' => now()->subDays(40),
        'description' => 'Último contato',
    ]);
    $template = JourneyTemplate::query()->create([
        'name' => 'Reativação Comercial',
        'type' => 'reactivation',
        'trigger_type' => 'sales_inactivity',
        'is_active' => true,
    ]);
    $template->steps()->create([
        'title' => 'Contato de reativação',
        'position' => 1,
        'delay_days' => 0,
        'task_title' => 'Retomar contato com {company}',
        'task_type' => 'reactivation',
        'task_priority' => 'high',
    ]);
    app(CommercialSettingsService::class)->forOrganization($organization)
        ->update(['reactivation_journey_template_id' => $template->id]);
    Mail::fake();
    Notification::fake();

    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);

    $enrollment = $template->enrollments()->where('company_id', $company->id)->firstOrFail();
    $reactivationTask = $enrollment->tasks()->firstOrFail();
    $commercialOccurrence = DB::table('commercial_alert_occurrences')
        ->where('company_id', $company->id)
        ->where('type', 'commercial_inactivity')
        ->firstOrFail();
    $relationshipOccurrence = DB::table('commercial_alert_occurrences')
        ->where('company_id', $company->id)
        ->where('type', 'relationship_inactivity')
        ->firstOrFail();
    expect($commercialOccurrence->task_id)->toBe($reactivationTask->id)
        ->and($relationshipOccurrence->task_id)->not->toBe($reactivationTask->id);

    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $seller->id,
        'description' => 'Nova compra',
        'amount' => '250.00',
        'currency' => 'BRL',
        'sold_at' => now($organization->timezone)->toDateTimeString(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('companies.activities.store', $company), [
        'type' => 'call',
        'occurred_at' => now()->toISOString(),
        'description' => 'Contato comercial realizado',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($enrollment->fresh()->status)->toBe('cancelled')
        ->and($reactivationTask->fresh()->status)->toBe('cancelled')
        ->and(DB::table('commercial_alert_occurrences')->where('company_id', $company->id)->where('status', 'active')->exists())->toBeFalse();

    $this->travel(100)->days();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    expect($template->enrollments()->where('company_id', $company->id)->count())->toBe(2)
        ->and($template->enrollments()->where('company_id', $company->id)->where('status', 'active')->exists())->toBeTrue();
});

it('reassigns automatic standalone tasks when a manager changes company ownership', function () {
    $this->travelTo('2026-11-11 15:00:00');
    $organization = Organization::factory()->create();
    $manager = User::factory()->create();
    $formerSeller = User::factory()->create();
    $newSeller = User::factory()->create();
    $organization->users()->attach($manager, ['role' => 'manager']);
    $organization->users()->attach($formerSeller, ['role' => 'salesperson']);
    $organization->users()->attach($newSeller, ['role' => 'salesperson']);
    app(TenantContext::class)->set($organization);
    $company = Company::factory()->ownedBy($formerSeller)->create([
        'organization_id' => $organization->id,
        'relationship_status' => 'customer',
    ]);
    $this->actingAs($formerSeller)->withSession(['active_organization_id' => $organization->id]);
    $this->post("/companies/{$company->id}/sales", [
        'seller_id' => $formerSeller->id,
        'description' => 'Compra antiga',
        'amount' => '100.00',
        'currency' => 'BRL',
        'sold_at' => now()->subDays(100)->toDateTimeString(),
    ])->assertRedirect();
    Mail::fake();
    Notification::fake();
    $this->artisan('commercial:scan-inactivity')->assertExitCode(0);
    $task = Task::query()->where('company_id', $company->id)->where('type', 'reactivation')->firstOrFail();

    $this->actingAs($manager)->patch("/companies/{$company->id}/owner", [
        'owner_user_id' => $newSeller->id,
    ])->assertRedirect();

    expect($task->fresh()->assigned_to)->toBe($newSeller->id);
    $this->actingAs($formerSeller)->get(route('tasks.show', $task->id))->assertForbidden();
    $this->actingAs($newSeller)->get(route('tasks.show', $task->id))->assertOk();
    Notification::assertSentTo($newSeller, TaskEventNotification::class);
});
