<?php

namespace Database\Seeders;

use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['name' => 'Marmoraria Exemplo Ltda.'],
            ['timezone' => 'America/Sao_Paulo'],
        );

        $members = [
            'owner@example.test' => ['name' => 'Camila Proprietária', 'role' => 'owner'],
            'manager@example.test' => ['name' => 'Pedro Gestor', 'role' => 'manager'],
            'ana@example.test' => ['name' => 'Ana Costa', 'role' => 'salesperson'],
            'bruno@example.test' => ['name' => 'Bruno Lima', 'role' => 'salesperson'],
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
        $ana = User::where('email', 'ana@example.test')->firstOrFail();
        $bruno = User::where('email', 'bruno@example.test')->firstOrFail();

        app(TenantContext::class)->set($organization);
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
            ],
        );

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

        $this->command?->info('Demonstração pronta: Marmoraria Exemplo Ltda. (owner@example.test / password).');
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
