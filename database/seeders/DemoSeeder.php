<?php

namespace Database\Seeders;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialAlertOccurrence;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerScoreHistory;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $organization = Organization::firstOrCreate(
            ['name' => 'Marmoraria Exemplo Ltda.'],
            ['timezone' => 'America/Sao_Paulo'],
        );

        $members = [
            'owner@example.test' => ['name' => 'Camila Proprietária', 'role' => 'owner'],
            'manager@example.test' => ['name' => 'Pedro Gestor', 'role' => 'manager'],
            'manager.sul@example.test' => ['name' => 'Mariana Souza', 'role' => 'manager'],
            'ana@example.test' => ['name' => 'Ana Costa', 'role' => 'salesperson'],
            'bruno@example.test' => ['name' => 'Bruno Lima', 'role' => 'salesperson'],
            'carlos@example.test' => ['name' => 'Carlos Mendes', 'role' => 'salesperson'],
            'daniela@example.test' => ['name' => 'Daniela Rocha', 'role' => 'salesperson'],
        ];

        foreach ($members as $email => $attributes) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $attributes['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => $attributes['role']],
            ]);
        }

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $manager = User::where('email', 'manager@example.test')->firstOrFail();
        $secondManager = User::where('email', 'manager.sul@example.test')->firstOrFail();
        $ana = User::where('email', 'ana@example.test')->firstOrFail();
        $bruno = User::where('email', 'bruno@example.test')->firstOrFail();
        $carlos = User::where('email', 'carlos@example.test')->firstOrFail();
        $daniela = User::where('email', 'daniela@example.test')->firstOrFail();

        app(TenantContext::class)->set($organization);
        $architectureTeam = $organization->teams()->firstOrCreate(
            ['name' => 'Equipe Norte'],
            ['created_by' => $owner->id],
        );
        $projectsTeam = $organization->teams()->firstOrCreate(
            ['name' => 'Equipe Sul'],
            ['created_by' => $owner->id],
        );
        $architectureTeam->update(['status' => 'active']);
        $projectsTeam->update(['status' => 'active']);
        $this->syncDemoTeamMember($architectureTeam, $manager, 'manager', $organization->id);
        $this->syncDemoTeamMember($architectureTeam, $ana, 'salesperson', $organization->id);
        $this->syncDemoTeamMember($architectureTeam, $carlos, 'salesperson', $organization->id);
        $this->syncDemoTeamMember($projectsTeam, $secondManager, 'manager', $organization->id);
        $this->syncDemoTeamMember($projectsTeam, $bruno, 'salesperson', $organization->id);
        $this->syncDemoTeamMember($projectsTeam, $daniela, 'salesperson', $organization->id);

        $journeys = app(JourneyService::class);
        $journeys->ensureDefaultTemplate($organization);

        $horizonte = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Escritório Horizonte'],
            [
                'trade_name' => 'Horizonte Arquitetura',
                'segment' => 'Arquitetura',
                'city' => 'São Paulo',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Indicação',
                'owner_user_id' => $ana->id,
                'team_id' => $architectureTeam->id,
                'phone' => '+55 11 4000-1200',
                'email' => 'contato@horizonte.example.test',
                'notes' => 'Relacionamento importante para projetos residenciais.',
            ],
        );

        $vale = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Estúdio Vale'],
            [
                'segment' => 'Arquitetura',
                'city' => 'Campinas',
                'state' => 'SP',
                'relationship_status' => 'prospect',
                'lead_source' => 'Evento profissional',
                'owner_user_id' => $ana->id,
                'team_id' => $architectureTeam->id,
            ],
        );
        $journeys->enrollDefaultForProspect($vale, $ana);

        $aurora = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Ateliê Aurora'],
            [
                'segment' => 'Design de interiores',
                'city' => 'Santos',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Prospecção',
                'owner_user_id' => $bruno->id,
                'team_id' => $projectsTeam->id,
            ],
        );

        $brisa = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Escritório Brisa'],
            [
                'segment' => 'Arquitetura',
                'city' => 'São José dos Campos',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Indicação',
                'owner_user_id' => $bruno->id,
                'team_id' => $projectsTeam->id,
            ],
        );
        $recomeco = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Escritório Recomeço'],
            [
                'segment' => 'Interiores',
                'city' => 'Santos',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Indicação',
                'owner_user_id' => $ana->id,
                'team_id' => $architectureTeam->id,
            ],
        );
        $crescente = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Escritório Crescente'],
            [
                'segment' => 'Arquitetura',
                'city' => 'Ribeirão Preto',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Evento profissional',
                'owner_user_id' => $bruno->id,
                'team_id' => $projectsTeam->id,
            ],
        );
        $boreal = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Escritório Boreal'],
            [
                'segment' => 'Arquitetura',
                'city' => 'Jundiaí',
                'state' => 'SP',
                'relationship_status' => 'prospect',
                'lead_source' => 'Indicação',
                'owner_user_id' => $carlos->id,
            ],
        );
        $porto = Company::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Ateliê Porto'],
            [
                'segment' => 'Design de interiores',
                'city' => 'Santos',
                'state' => 'SP',
                'relationship_status' => 'customer',
                'lead_source' => 'Evento profissional',
                'owner_user_id' => $daniela->id,
            ],
        );
        $horizonte->update(['team_id' => $architectureTeam->id]);
        $vale->update(['team_id' => $architectureTeam->id]);
        $recomeco->update(['team_id' => $architectureTeam->id]);
        $aurora->update(['team_id' => $projectsTeam->id]);
        $brisa->update(['team_id' => $projectsTeam->id]);
        $crescente->update(['team_id' => $projectsTeam->id]);
        $boreal->update(['team_id' => $architectureTeam->id]);
        $porto->update(['team_id' => $projectsTeam->id]);

        Task::query()->firstOrCreate(
            ['company_id' => $aurora->id, 'title' => 'Enviar amostras solicitadas'],
            [
                'organization_id' => $organization->id,
                'assigned_to' => $bruno->id,
                'created_by' => $bruno->id,
                'type' => 'follow_up',
                'priority' => 'high',
                'status' => 'pending',
                'due_at' => now()->addHours(3),
                'origin' => 'manual',
            ],
        );

        $marina = Contact::firstOrCreate(
            ['organization_id' => $organization->id, 'email' => 'marina@example.test'],
            ['name' => 'Marina Souza', 'phone' => '+55 11 99900-1001', 'job_title' => 'Sócia-proprietária', 'status' => 'active'],
        );
        $rafael = Contact::firstOrCreate(
            ['organization_id' => $organization->id, 'email' => 'rafael@example.test'],
            ['name' => 'Rafael Nunes', 'phone' => '+55 11 99900-1002', 'job_title' => 'Responsável por orçamentos', 'status' => 'active'],
        );
        $luana = Contact::firstOrCreate(
            ['organization_id' => $organization->id, 'email' => 'luana@example.test'],
            ['name' => 'Luana Prado', 'phone' => '+55 11 99900-1003', 'job_title' => 'Arquiteta especificadora', 'status' => 'active'],
        );

        $this->linkContact($horizonte, $marina, ['owner', 'decision_maker'], true);
        $this->linkContact($horizonte, $rafael, ['budget_manager', 'buyer'], false);
        $this->linkContact($horizonte, $luana, ['specifier', 'influencer'], false);
        $this->linkContact($vale, $marina, ['partner', 'decision_maker'], true);
        $this->linkContact($brisa, $rafael, ['budget_manager', 'buyer'], true);
        $this->linkContact($recomeco, $marina, ['decision_maker'], true);
        $this->linkContact($crescente, $rafael, ['buyer'], true);

        $horizonte->activities()->firstOrCreate(
            ['description' => 'Conversa sobre dois novos projetos residenciais em desenvolvimento.'],
            [
                'organization_id' => $organization->id,
                'contact_id' => $rafael->id,
                'user_id' => $ana->id,
                'type' => 'call',
                'occurred_at' => now()->subDays(90),
                'result' => 'O escritório pediu referências de materiais para os projetos.',
                'next_action' => 'Retomar contato e apresentar as novidades da coleção.',
            ],
        );

        $aurora->activities()->firstOrCreate(
            ['description' => 'Visita para conhecer o andamento dos projetos atuais.'],
            [
                'organization_id' => $organization->id,
                'contact_id' => $luana->id,
                'user_id' => $bruno->id,
                'type' => 'visit',
                'occurred_at' => now()->subDays(8),
                'result' => 'Mapeadas duas oportunidades para o próximo trimestre.',
                'next_action' => 'Enviar amostras solicitadas.',
            ],
        );

        $this->seedCommercialScenarios($organization, $horizonte, $vale, $brisa, $recomeco, $crescente, $ana, $bruno, $owner, $rafael);

        $this->command?->info('Demonstração pronta: Marmoraria Exemplo Ltda. (owner@example.test / password).');
    }

    private function seedCommercialScenarios(
        Organization $organization,
        Company $strategic,
        Company $prospect,
        Company $inactive,
        Company $reactivated,
        Company $evolving,
        User $ana,
        User $bruno,
        User $creator,
        Contact $recommendedContact,
    ): void {
        $settings = app(CommercialSettingsService::class)->forOrganization($organization);
        $postSale = $this->firstOrCreateJourney(
            $organization,
            'Acompanhamento pós-venda',
            'post_sale',
            'first_sale',
            [
                ['title' => 'Conferir a primeira entrega', 'delay_days' => 3, 'task_title' => 'Conferir a experiência da primeira compra de {company}.', 'task_type' => 'follow_up'],
                ['title' => 'Acompanhar o projeto', 'delay_days' => 10, 'task_title' => 'Acompanhar o andamento do projeto de {company}.', 'task_type' => 'follow_up'],
            ],
        );
        $reactivation = $this->firstOrCreateJourney(
            $organization,
            'Reativação Comercial',
            'reactivation',
            'sales_inactivity',
            [
                ['title' => 'Contato inicial de reativação', 'delay_days' => 0, 'task_title' => 'Retomar contato com {company}.', 'task_type' => 'reactivation'],
                ['title' => 'Acompanhamento', 'delay_days' => 7, 'task_title' => 'Acompanhar oportunidade de {company}.', 'task_type' => 'follow_up'],
                ['title' => 'Revisão do relacionamento', 'delay_days' => 15, 'task_title' => 'Revisar o relacionamento comercial com {company}.', 'task_type' => 'follow_up'],
            ],
        );
        $settings->update([
            'post_sale_journey_template_id' => $postSale->id,
            'reactivation_journey_template_id' => $reactivation->id,
        ]);

        foreach ([
            [$strategic, $ana, 'horizonte-1', 'Compra recorrente de revestimentos', '42000.00', 240],
            [$strategic, $ana, 'horizonte-2', 'Reposição de materiais', '38000.00', 110],
            [$strategic, $ana, 'horizonte-3', 'Pedido para novos projetos', '40000.00', 15],
            [$inactive, $bruno, 'brisa-1', 'Compra anterior de materiais', '8000.00', 110],
            [$reactivated, $ana, 'recomeco-1', 'Compra anterior à pausa', '6500.00', 180],
            [$reactivated, $ana, 'recomeco-2', 'Compra após ação de relacionamento', '8500.00', 5],
            [$evolving, $bruno, 'crescente-1', 'Primeira compra do ciclo', '18000.00', 180],
            [$evolving, $bruno, 'crescente-2', 'Compra recorrente do ciclo', '20000.00', 90],
            [$evolving, $bruno, 'crescente-3', 'Pedido recente em expansão', '22000.00', 20],
        ] as [$company, $seller, $externalId, $description, $amount, $daysAgo]) {
            $this->recordSale($company, $seller, $creator, $externalId, $description, $amount, $daysAgo);
        }

        $inactive->activities()->firstOrCreate(
            ['description' => 'Último contato comercial antes da pausa.'],
            [
                'organization_id' => $organization->id,
                'contact_id' => $recommendedContact->id,
                'user_id' => $bruno->id,
                'type' => 'call',
                'occurred_at' => CarbonImmutable::now('UTC')->subDays(45),
                'result' => 'O escritório adiou novos projetos.',
            ],
        );
        $strategic->activities()->firstOrCreate(
            ['description' => 'Revisão recente dos projetos ativos.'],
            [
                'organization_id' => $organization->id,
                'contact_id' => $recommendedContact->id,
                'user_id' => $ana->id,
                'type' => 'meeting',
                'occurred_at' => CarbonImmutable::now('UTC')->subDays(2),
                'result' => 'Relacionamento ativo e novas obras mapeadas.',
            ],
        );

        app(CustomerScoreService::class)->updateManualCriteria($strategic, [
            'potential_points' => 18,
            'relationship_points' => 18,
        ], 'Cenário demonstrativo estratégico');
        app(CustomerScoreService::class)->updateManualCriteria($inactive, [
            'potential_points' => 12,
            'relationship_points' => 10,
        ], 'Cenário demonstrativo inativo');
        app(CustomerScoreService::class)->updateManualCriteria($reactivated, [
            'potential_points' => 10,
            'relationship_points' => 12,
        ], 'Cenário demonstrativo reativado');
        app(CustomerScoreService::class)->updateManualCriteria($evolving, [
            'potential_points' => 8,
            'relationship_points' => 8,
        ], 'Cenário demonstrativo em evolução');

        if (! CustomerScoreHistory::query()->where('company_id', $evolving->id)->where('reason', 'Evolução demonstrativa para Faixa C')->exists()) {
            CustomerScoreHistory::query()->create([
                'company_id' => $evolving->id,
                'volume_points' => 16,
                'frequency_points' => 10,
                'potential_points' => 8,
                'relationship_points' => 8,
                'total_score' => 42,
                'tier' => 'C',
                'is_complete' => true,
                'reason' => 'Evolução demonstrativa para Faixa C',
                'recorded_at' => CarbonImmutable::now('UTC')->subMonth(),
            ]);
        }

        $this->seedInactivityHistory($organization, $inactive, $bruno, $recommendedContact);
        $this->seedReactivationHistory($organization, $reactivated, $ana, $creator);

        app(JourneyService::class)->enrollDefaultForProspect($prospect, $ana);
    }

    private function syncDemoTeamMember(Team $team, User $user, string $role, int $organizationId): void
    {
        $team->users()->syncWithoutDetaching([
            $user->id => [
                'organization_id' => $organizationId,
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
            ],
        ]);
    }

    private function recordSale(
        Company $company,
        User $seller,
        User $creator,
        string $externalId,
        string $description,
        string $amount,
        int $daysAgo,
    ): Sale {
        return Sale::query()->firstOrCreate(
            ['external_id' => 'demo-'.$externalId],
            [
                'company_id' => $company->id,
                'seller_id' => $seller->id,
                'created_by' => $creator->id,
                'description' => $description,
                'amount' => $amount,
                'currency' => 'BRL',
                'sold_at' => CarbonImmutable::now('UTC')->subDays($daysAgo),
                'status' => 'confirmed',
            ],
        );
    }

    /** @param list<array{title: string, delay_days: int, task_title: string, task_type: string}> $steps */
    private function firstOrCreateJourney(
        Organization $organization,
        string $name,
        string $type,
        string $triggerType,
        array $steps,
    ): JourneyTemplate {
        $template = JourneyTemplate::query()->firstOrCreate(
            ['name' => $name],
            ['description' => 'Modelo demonstrativo de acompanhamento comercial.', 'type' => $type, 'trigger_type' => $triggerType, 'is_active' => true, 'is_default' => false],
        );

        if (! $template->steps()->exists()) {
            foreach ($steps as $index => $step) {
                $template->steps()->create([
                    ...$step,
                    'position' => $index + 1,
                    'task_priority' => $type === 'reactivation' ? 'high' : 'normal',
                ]);
            }
        }

        return $template;
    }

    private function seedInactivityHistory(
        Organization $organization,
        Company $company,
        User $seller,
        Contact $contact,
    ): void {
        $task = Task::query()->firstOrCreate(
            ['company_id' => $company->id, 'title' => 'Reativar relacionamento com Escritório Brisa'],
            [
                'assigned_to' => $seller->id,
                'created_by' => $seller->id,
                'contact_id' => $contact->id,
                'type' => 'reactivation',
                'priority' => 'high',
                'status' => 'pending',
                'due_at' => CarbonImmutable::now('UTC'),
                'description' => 'Escritório Brisa está há 110 dias sem compra confirmada. Rafael é o responsável por orçamentos.',
                'origin' => 'automatic',
            ],
        );

        CommercialAlertOccurrence::query()->firstOrCreate(
            ['company_id' => $company->id, 'type' => 'commercial_inactivity', 'occurrence_key' => 'demo-brisa-commercial-inactivity'],
            [
                'anchor_at' => CarbonImmutable::now('UTC')->subDays(110),
                'status' => 'active',
                'reason' => $task->description,
                'task_id' => $task->id,
                'recommended_contact_id' => $contact->id,
                'first_detected_at' => CarbonImmutable::now('UTC'),
            ],
        );
        CommercialAlertOccurrence::query()->firstOrCreate(
            ['company_id' => $company->id, 'type' => 'relationship_inactivity', 'occurrence_key' => 'demo-brisa-relationship-inactivity'],
            [
                'anchor_at' => CarbonImmutable::now('UTC')->subDays(45),
                'status' => 'active',
                'reason' => 'O último contato comercial registrado ocorreu há 45 dias.',
                'task_id' => $task->id,
                'recommended_contact_id' => $contact->id,
                'first_detected_at' => CarbonImmutable::now('UTC'),
            ],
        );
    }

    private function seedReactivationHistory(
        Organization $organization,
        Company $company,
        User $seller,
        User $creator,
    ): void {
        $task = Task::query()->firstOrCreate(
            ['company_id' => $company->id, 'title' => 'Acompanhar retorno do Escritório Recomeço'],
            [
                'assigned_to' => $seller->id,
                'created_by' => $creator->id,
                'type' => 'reactivation',
                'priority' => 'high',
                'status' => 'completed',
                'due_at' => CarbonImmutable::now('UTC')->subDays(10),
                'completed_at' => CarbonImmutable::now('UTC')->subDays(5),
                'completed_by' => $seller->id,
                'completion_notes' => 'O cliente confirmou um novo projeto.',
                'description' => 'Acompanhamento após período de inatividade.',
                'origin' => 'automatic',
            ],
        );

        CommercialAlertOccurrence::query()->firstOrCreate(
            ['company_id' => $company->id, 'type' => 'commercial_inactivity', 'occurrence_key' => 'demo-recomeco-reactivated'],
            [
                'anchor_at' => CarbonImmutable::now('UTC')->subDays(180),
                'status' => 'resolved',
                'reason' => 'Cliente ficou sem compras e recebeu acompanhamento.',
                'task_id' => $task->id,
                'first_detected_at' => CarbonImmutable::now('UTC')->subDays(70),
                'resolved_at' => CarbonImmutable::now('UTC')->subDays(5),
                'resolution_reason' => 'Nova compra confirmada.',
            ],
        );
    }

    /** @param array<int, string> $roles */
    private function linkContact(Company $company, Contact $contact, array $roles, bool $isPrimary): void
    {
        $company->contacts()->syncWithoutDetaching([
            $contact->id => [
                'organization_id' => $company->organization_id,
                'roles' => $roles,
                'is_primary' => $isPrimary,
            ],
        ]);
    }
}
