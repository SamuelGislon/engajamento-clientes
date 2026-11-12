import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    ChartNoAxesCombined,
    Clock3,
    Search,
    UsersRound,
} from 'lucide-react';
import CommercialReportController from '@/actions/App/Http/Controllers/CommercialReportController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { show as showTask } from '@/actions/App/Http/Controllers/TaskController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Option = { id: number; name: string };
type PageLink = { url: string | null; label: string; active: boolean };
type Page<T> = {
    data: T[];
    links: PageLink[];
    total: number;
    from: number | null;
    to: number | null;
};
type Seller = {
    id: number;
    name: string;
    portfolio: number;
    prospects: number;
    commercially_inactive: number;
    without_recent_contact: number;
    active_journeys: number;
    sales_count: number;
    revenue: string;
    tasks: {
        eligible: number;
        completed_on_time: number;
        completed_late: number;
        pending: number;
        overdue: number;
        cancelled: number;
        completion_rate: number;
    };
};
type RelationshipCompany = {
    id: number;
    name: string;
    relationship_status: string;
    activities_max_occurred_at: string | null;
    active_journeys_count: number;
    owner: { id: number; name: string } | null;
    team: { id: number; name: string } | null;
    customer_score: { tier: string | null } | null;
};
type AttentionTask = {
    id: number;
    title: string;
    due_at: string;
    company: { id: number; name: string } | null;
    assignee: { id: number; name: string };
};
type UnassignedCompany = {
    id: number;
    name: string;
    team: { id: number; name: string } | null;
};

const taskStatuses = [
    ['pending', 'Pendente'],
    ['in_progress', 'Em andamento'],
    ['postponed', 'Adiada'],
    ['completed', 'Concluída'],
    ['cancelled', 'Cancelada'],
];
const taskTypes = [
    ['first_contact', 'Primeiro contato'],
    ['call', 'Ligação'],
    ['whatsapp', 'WhatsApp'],
    ['email', 'E-mail'],
    ['meeting', 'Reunião'],
    ['visit', 'Visita'],
    ['prospecting', 'Prospecção'],
    ['follow_up', 'Acompanhamento'],
    ['reactivation', 'Reativação'],
    ['custom', 'Personalizada'],
];

function money(value: string): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value));
}

function date(value: string | null, timezone: string): string {
    if (!value) return 'Sem registro';
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'medium',
        timeZone: timezone,
    }).format(new Date(value));
}

function Pagination({ page }: { page: Page<unknown> }) {
    if (page.links.length < 4) return null;
    return (
        <nav
            className="flex flex-wrap gap-2 border-t border-border/70 px-5 py-4"
            aria-label="Paginação"
        >
            {page.links.map((link, index) =>
                link.url ? (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url}
                        preserveScroll
                        className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={`${link.label}-${index}`}
                        className="rounded-md border px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}

export default function CommercialReports({
    summary,
    sellerPerformance,
    teams,
    sellers,
    filters,
    attention,
    timezone,
    relationshipCompanies,
    salesByMonth,
    salesByTeam,
}: {
    summary: {
        companies: number;
        prospects: number;
        customers: number;
        unassigned_companies: number;
        commercially_inactive: number;
        without_recent_contact: number;
        reactivated: number;
        active_journeys: number;
        tier_distribution: Record<'A' | 'B' | 'C' | 'D', number>;
        tasks: {
            eligible: number;
            completed_on_time: number;
            completed_late: number;
            pending: number;
            overdue: number;
            cancelled: number;
            completion_rate: number;
        };
        sales: {
            count: number;
            revenue: string;
            average_ticket: string | null;
            buyers: number;
        };
    };
    sellerPerformance: Page<Seller>;
    teams: Option[];
    sellers: Option[];
    filters: {
        from: string;
        to: string;
        team_id: number | null;
        seller_id: number | null;
        status: string | null;
        type: string | null;
    };
    attention: {
        overdue_tasks: AttentionTask[];
        unassigned_companies: UnassignedCompany[];
    };
    timezone: string;
    relationshipCompanies: Page<RelationshipCompany> | null;
    salesByMonth: { month: string; count: number; revenue: string }[];
    salesByTeam: {
        team_id: number | null;
        team_name: string;
        sellers_count: number;
        sales_count: number;
        revenue: string;
    }[];
}) {
    const form = useForm({
        from: filters.from,
        to: filters.to,
        team_id: filters.team_id?.toString() ?? '',
        seller_id: filters.seller_id?.toString() ?? '',
        status: filters.status ?? '',
        type: filters.type ?? '',
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get(CommercialReportController.url(), {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <>
            <Head title="Relatórios comerciais" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="flex items-center gap-2 text-sm font-medium text-primary">
                        <ChartNoAxesCombined className="size-4" /> Gestão
                        comercial
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Relatórios
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Atividades, relacionamento e vendas no fuso {timezone}.
                    </p>
                </header>

                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6"
                >
                    <Input
                        aria-label="Data inicial"
                        type="date"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        aria-label="Data final"
                        type="date"
                        value={form.data.to}
                        onChange={(event) =>
                            form.setData('to', event.target.value)
                        }
                    />
                    <select
                        aria-label="Equipe"
                        value={form.data.team_id}
                        onChange={(event) => {
                            form.setData((data) => ({
                                ...data,
                                team_id: event.target.value,
                                seller_id: '',
                            }));
                        }}
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as equipes</option>
                        {teams.map((team) => (
                            <option key={team.id} value={team.id}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Vendedor"
                        value={form.data.seller_id}
                        onChange={(event) =>
                            form.setData('seller_id', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os vendedores</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>
                                {seller.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Situação da atividade"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as situações</option>
                        {taskStatuses.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-2">
                        <select
                            aria-label="Tipo de atividade"
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                            className="h-10 min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Todos os tipos</option>
                            {taskTypes.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <Button type="submit" disabled={form.processing}>
                            <Search className="size-4" />
                            <span className="sr-only">Filtrar</span>
                        </Button>
                    </div>
                </form>
                {(form.errors.from ||
                    form.errors.to ||
                    form.errors.team_id ||
                    form.errors.seller_id ||
                    form.errors.status ||
                    form.errors.type) && (
                    <p role="alert" className="text-sm text-destructive">
                        {form.errors.from ??
                            form.errors.to ??
                            form.errors.team_id ??
                            form.errors.seller_id ??
                            form.errors.status ??
                            form.errors.type}
                    </p>
                )}

                <section
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    aria-label="Resumo do período"
                >
                    <Metric
                        label="Empresas acompanhadas"
                        value={summary.companies}
                        detail={`${summary.prospects} prospectos · ${summary.customers} clientes`}
                        icon={Building2}
                    />
                    <Metric
                        label="Clientes sem compras recentes"
                        value={summary.commercially_inactive}
                        detail={`${summary.without_recent_contact} também sem contato recente`}
                        icon={AlertTriangle}
                    />
                    <Metric
                        label="Jornadas em andamento"
                        value={summary.active_journeys}
                        detail={`${summary.reactivated} clientes reativados no período`}
                        icon={UsersRound}
                    />
                    <Metric
                        label="Tarefas atrasadas"
                        value={summary.tasks.overdue}
                        detail={`${summary.tasks.pending} pendentes · ${summary.tasks.cancelled} canceladas`}
                        icon={Clock3}
                    />
                    <Metric
                        label="Conclusão de atividades"
                        value={`${summary.tasks.completion_rate.toLocaleString('pt-BR')}%`}
                        detail={`${summary.tasks.completed_on_time} no prazo · ${summary.tasks.completed_late} com atraso · ${summary.tasks.eligible} elegíveis`}
                        icon={ChartNoAxesCombined}
                    />
                    <Metric
                        label="Vendas confirmadas"
                        value={summary.sales.count}
                        detail={`${summary.sales.buyers} clientes compradores`}
                        icon={ChartNoAxesCombined}
                    />
                    <Metric
                        label="Faturamento confirmado"
                        value={money(summary.sales.revenue)}
                        detail={`Ticket médio ${summary.sales.average_ticket ? money(summary.sales.average_ticket) : '—'}`}
                        icon={ChartNoAxesCombined}
                    />
                    <Metric
                        label="Empresas sem responsável"
                        value={summary.unassigned_companies}
                        detail="Empresas da carteira filtrada"
                        icon={AlertTriangle}
                    />
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-4">
                        <h2 className="font-semibold">
                            Desempenho por vendedor
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Tarefas futuras não entram na taxa; canceladas são
                            excluídas do denominador.
                        </p>
                    </div>
                    {sellerPerformance.data.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-muted-foreground">
                            Nenhum vendedor encontrado para esses filtros.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground uppercase">
                                    <tr>
                                        {[
                                            'Vendedor',
                                            'Carteira',
                                            'Concluídas',
                                            'Pendentes',
                                            'Atrasadas',
                                            'Inativos',
                                            'Jornadas',
                                            'Vendas',
                                            'Faturamento',
                                        ].map((heading) => (
                                            <th
                                                key={heading}
                                                className="px-4 py-3 font-medium"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/60">
                                    {sellerPerformance.data.map((seller) => (
                                        <tr key={seller.id}>
                                            <td className="px-4 py-3 font-medium">
                                                <Link
                                                    href={CommercialReportController.url(
                                                        {
                                                            query: {
                                                                ...filters,
                                                                seller_id:
                                                                    seller.id,
                                                            },
                                                        },
                                                    )}
                                                    className="hover:text-primary hover:underline"
                                                >
                                                    {seller.name}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    Taxa{' '}
                                                    {seller.tasks.completion_rate.toLocaleString(
                                                        'pt-BR',
                                                    )}
                                                    %
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.portfolio}
                                                <div className="text-xs text-muted-foreground">
                                                    {seller.prospects}{' '}
                                                    prospectos
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.tasks
                                                    .completed_on_time +
                                                    seller.tasks.completed_late}
                                                <div className="text-xs text-muted-foreground">
                                                    {
                                                        seller.tasks
                                                            .completed_on_time
                                                    }{' '}
                                                    no prazo
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.tasks.pending}
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.tasks.overdue}
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.commercially_inactive}
                                                <div className="text-xs text-muted-foreground">
                                                    {
                                                        seller.without_recent_contact
                                                    }{' '}
                                                    sem contato
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.active_journeys}
                                            </td>
                                            <td className="px-4 py-3">
                                                {seller.sales_count}
                                            </td>
                                            <td className="px-4 py-3">
                                                {money(seller.revenue)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination page={sellerPerformance} />
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="border-b border-border/70 px-5 py-4">
                            <h2 className="font-semibold">
                                Relacionamento que precisa de atenção
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Sem contato recente primeiro; abra uma empresa
                                para agir.
                            </p>
                        </div>
                        {!relationshipCompanies ||
                        relationshipCompanies.data.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-muted-foreground">
                                Nenhuma empresa encontrada.
                            </p>
                        ) : (
                            <div className="divide-y divide-border/60">
                                {relationshipCompanies.data.map((company) => (
                                    <Link
                                        key={company.id}
                                        href={showCompany.url(company.id)}
                                        className="flex items-center justify-between gap-4 px-5 py-4 hover:bg-muted/30"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {company.name}
                                            </span>
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                {company.owner?.name ??
                                                    'Sem responsável'}{' '}
                                                ·{' '}
                                                {company.team?.name ??
                                                    'Sem equipe'}{' '}
                                                · último contato:{' '}
                                                {date(
                                                    company.activities_max_occurred_at,
                                                    timezone,
                                                )}
                                            </span>
                                        </span>
                                        <span className="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs">
                                            Faixa{' '}
                                            {company.customer_score?.tier ??
                                                '—'}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}
                        {relationshipCompanies && (
                            <Pagination page={relationshipCompanies} />
                        )}
                    </section>
                    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="border-b border-border/70 px-5 py-4">
                            <h2 className="font-semibold">
                                Situações que exigem atenção
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Tarefas atrasadas e empresas ainda sem
                                responsável.
                            </p>
                        </div>
                        <div className="divide-y divide-border/60">
                            {attention.overdue_tasks.map((task) => (
                                <Link
                                    key={`task-${task.id}`}
                                    href={showTask.url(task.id)}
                                    className="block px-5 py-4 hover:bg-muted/30"
                                >
                                    <span className="font-medium">
                                        {task.title}
                                    </span>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        {task.company?.name ??
                                            'Tarefa sem empresa'}{' '}
                                        · {task.assignee.name} · vencida em{' '}
                                        {date(task.due_at, timezone)}
                                    </span>
                                </Link>
                            ))}
                            {attention.unassigned_companies.map((company) => (
                                <Link
                                    key={`company-${company.id}`}
                                    href={showCompany.url(company.id)}
                                    className="block px-5 py-4 hover:bg-muted/30"
                                >
                                    <span className="font-medium">
                                        {company.name}
                                    </span>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        Sem responsável
                                        {company.team
                                            ? ` · ${company.team.name}`
                                            : ''}
                                    </span>
                                </Link>
                            ))}
                            {attention.overdue_tasks.length === 0 &&
                                attention.unassigned_companies.length === 0 && (
                                    <p className="px-5 py-8 text-sm text-muted-foreground">
                                        Nenhuma situação prioritária neste
                                        período.
                                    </p>
                                )}
                        </div>
                    </section>
                </div>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-4">
                        <h2 className="font-semibold">Resultados por equipe</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Vendas novas usam a equipe registrada no momento da
                            venda; vendas antigas usam a equipe atual da
                            empresa.
                        </p>
                    </div>
                    {salesByTeam.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-muted-foreground">
                            Nenhuma venda confirmada no período selecionado.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground uppercase">
                                    <tr>
                                        {[
                                            'Equipe histórica',
                                            'Vendedores',
                                            'Vendas confirmadas',
                                            'Faturamento',
                                        ].map((heading) => (
                                            <th
                                                key={heading}
                                                className="px-4 py-3 font-medium"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/60">
                                    {salesByTeam.map((team) => (
                                        <tr key={team.team_id ?? 'unassigned'}>
                                            <td className="px-4 py-3 font-medium">
                                                {team.team_name}
                                            </td>
                                            <td className="px-4 py-3">
                                                {team.sellers_count}
                                            </td>
                                            <td className="px-4 py-3">
                                                {team.sales_count}
                                            </td>
                                            <td className="px-4 py-3">
                                                {money(team.revenue)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-4">
                        <h2 className="font-semibold">
                            Vendas confirmadas por mês
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            A atribuição histórica da equipe é usada quando
                            existe; registros antigos usam a equipe atual da
                            empresa.
                        </p>
                    </div>
                    <div className="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
                        {salesByMonth.map((month) => (
                            <div
                                key={month.month}
                                className="rounded-xl bg-muted/40 p-4"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {new Intl.DateTimeFormat('pt-BR', {
                                        month: 'long',
                                        year: 'numeric',
                                        timeZone: 'UTC',
                                    }).format(
                                        new Date(`${month.month}-01T12:00:00Z`),
                                    )}
                                </p>
                                <p className="mt-2 text-lg font-semibold">
                                    {money(month.revenue)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {month.count} vendas confirmadas
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="rounded-2xl border border-border/70 bg-card p-5">
                    <h2 className="font-semibold">Classificação comercial</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Distribuição das empresas visíveis segundo a pontuação
                        atual.
                    </p>
                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {(['A', 'B', 'C', 'D'] as const).map((tier) => (
                            <div
                                key={tier}
                                className="rounded-xl bg-muted/40 p-4"
                            >
                                <span className="text-sm text-muted-foreground">
                                    Faixa {tier}
                                </span>
                                <p className="mt-1 text-2xl font-semibold">
                                    {summary.tier_distribution[tier]}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            </main>
        </>
    );
}

function Metric({
    label,
    value,
    detail,
    icon: Icon,
}: {
    label: string;
    value: number | string;
    detail: string;
    icon: typeof Building2;
}) {
    return (
        <article className="rounded-2xl border border-border/70 bg-card p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="mt-2 text-2xl font-semibold">
                        {typeof value === 'number'
                            ? value.toLocaleString('pt-BR')
                            : value}
                    </p>
                </div>
                <Icon className="size-5 text-primary" />
            </div>
            <p className="mt-2 text-xs text-muted-foreground">{detail}</p>
        </article>
    );
}
