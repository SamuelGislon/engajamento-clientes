import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    CircleUserRound,
    Clock3,
    AlertTriangle,
    ListTodo,
    MessageSquareText,
    Sparkles,
    Route,
    ShoppingBag,
    DollarSign,
    RotateCcw,
} from 'lucide-react';
import {
    create as createCompany,
    index as companiesIndex,
    show as showCompany,
} from '@/actions/App/Http/Controllers/CompanyController';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import { show as showTask } from '@/actions/App/Http/Controllers/TaskController';
import type { BreadcrumbItem } from '@/types';

type Activity = {
    id: number;
    type: string;
    occurred_at: string;
    description: string;
    company: { id: number; name: string };
    contact: { id: number; name: string } | null;
    user: { id: number; name: string };
};

type Company = {
    id: number;
    name: string;
    relationship_status: string;
    activities_max_occurred_at: string | null;
};
type CommercialMetrics = {
    customers_with_purchases: number;
    prospects_without_purchases: number;
    commercially_inactive: number;
    without_recent_contact: number;
    reactivated_this_period: number;
    confirmed_sales_period: number;
    revenue_period: string;
    average_ticket_period: string | null;
    tier_distribution: Record<'A' | 'B' | 'C' | 'D', number>;
    pending_reactivation_tasks: number;
};
type CommercialAttention = {
    id: number;
    type: 'commercial_inactivity' | 'relationship_inactivity';
    reason: string;
    first_detected_at: string;
    company: { id: number; name: string };
    owner: { id: number; name: string } | null;
    tier: string | null;
    last_purchase_at: string | null;
    last_contact_at: string | null;
    suggested_action: string;
    recommended_contact: { id: number; name: string } | null;
    task: { id: number; title: string; type: string; can_view: boolean } | null;
};

const typeLabels: Record<string, string> = {
    call: 'Ligação',
    message: 'Mensagem',
    whatsapp: 'WhatsApp',
    email: 'E-mail',
    meeting: 'Reunião',
    visit: 'Visita',
    presentation: 'Apresentação',
    material: 'Envio de material',
    quote_follow_up: 'Acompanhamento de orçamento',
    other: 'Interação',
};

const statusLabels: Record<string, string> = {
    prospect: 'Prospecto',
    customer: 'Cliente',
    inactive: 'Inativo',
    partner: 'Parceiro',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Visão geral', href: DashboardController.url() },
];

function dateLabel(value: string | null, timeZone?: string): string {
    if (!value) return 'Nenhuma interação registrada';
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'medium',
        timeZone,
    }).format(new Date(value));
}

function moneyLabel(value: string): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value));
}

export default function Dashboard({
    metrics,
    recentActivities,
    attentionCompanies,
    commercialMetrics,
    commercialAttention,
    commercialTimezone,
}: {
    metrics: {
        companies: number;
        prospects: number;
        customers: number;
        contacts: number;
        tasks_due_today: number;
        overdue_tasks: number;
        active_journeys: number;
    };
    recentActivities: Activity[];
    attentionCompanies: Company[];
    commercialMetrics: CommercialMetrics;
    commercialAttention: CommercialAttention[];
    commercialTimezone: string;
}) {
    const { auth, activeOrganization } = usePage().props;
    const firstName = auth.user?.name?.split(' ')[0] ?? 'olá';

    return (
        <>
            <Head title="Visão geral" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-8 px-5 py-8 md:px-8">
                <section className="flex flex-col gap-5 rounded-2xl bg-[#183b32] px-6 py-7 text-white shadow-sm md:flex-row md:items-end md:justify-between md:px-8">
                    <div className="max-w-2xl">
                        <p className="mb-3 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-[#b7d7ca] uppercase">
                            <Sparkles className="size-4" /> Seu relacionamento,
                            em movimento
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                            Bom dia, {firstName}.
                        </h1>
                        <p className="mt-3 max-w-xl text-sm leading-6 text-white/75">
                            Acompanhe sua carteira e mantenha cada conversa
                            comercial avançando.
                        </p>
                    </div>
                    <div className="flex items-center gap-3 rounded-xl border border-white/15 bg-white/5 px-4 py-3 text-sm">
                        <Building2 className="size-4 text-[#b7d7ca]" />
                        <span className="font-medium">
                            {activeOrganization?.name ?? 'Sua organização'}
                        </span>
                    </div>
                </section>

                <section
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Indicadores da carteira"
                >
                    <Metric
                        label="Empresas na carteira"
                        value={metrics.companies}
                        detail="Relacionamentos acompanhados"
                        icon={Building2}
                    />
                    <Metric
                        label="Prospectos"
                        value={metrics.prospects}
                        detail="Oportunidades em desenvolvimento"
                        icon={Sparkles}
                    />
                    <Metric
                        label="Clientes"
                        value={metrics.customers}
                        detail="Relacionamentos com compra"
                        icon={CircleUserRound}
                    />
                    <Metric
                        label="Profissionais"
                        value={metrics.contacts}
                        detail="Pessoas ligadas às empresas"
                        icon={MessageSquareText}
                    />
                    <Metric
                        label="Atividades para hoje"
                        value={metrics.tasks_due_today}
                        detail="Tarefas com vencimento hoje"
                        icon={ListTodo}
                    />
                    <Metric
                        label="Atividades atrasadas"
                        value={metrics.overdue_tasks}
                        detail="Ações abertas após o vencimento"
                        icon={AlertTriangle}
                    />
                    <Metric
                        label="Jornadas ativas"
                        value={metrics.active_journeys}
                        detail="Inscrições em andamento ou pausadas"
                        icon={Route}
                    />
                </section>

                <section
                    className="flex flex-col gap-5"
                    aria-label="Inteligência comercial"
                >
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">
                            Inteligência comercial
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Indicadores calculados a partir das vendas e
                            interações registradas.
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric
                            label="Clientes com compras"
                            value={commercialMetrics.customers_with_purchases}
                            detail="Empresas com venda confirmada"
                            icon={ShoppingBag}
                        />
                        <Metric
                            label="Prospectos sem compras"
                            value={
                                commercialMetrics.prospects_without_purchases
                            }
                            detail="Ainda não são clientes inativos"
                            icon={Sparkles}
                        />
                        <Metric
                            label="Clientes sem compras recentes"
                            value={commercialMetrics.commercially_inactive}
                            detail="Acima do intervalo da organização"
                            icon={AlertTriangle}
                        />
                        <Metric
                            label="Sem contato recente"
                            value={commercialMetrics.without_recent_contact}
                            detail="Interações comerciais vencidas"
                            icon={MessageSquareText}
                        />
                        <Metric
                            label="Reativados neste mês"
                            value={commercialMetrics.reactivated_this_period}
                            detail="Voltaram a comprar após alerta"
                            icon={RotateCcw}
                        />
                        <Metric
                            label="Vendas confirmadas"
                            value={commercialMetrics.confirmed_sales_period}
                            detail="No mês atual da organização"
                            icon={ShoppingBag}
                        />
                        <Metric
                            label="Faturamento registrado"
                            value={moneyLabel(commercialMetrics.revenue_period)}
                            detail="Vendas confirmadas no mês"
                            icon={DollarSign}
                        />
                        <Metric
                            label="Ticket médio"
                            value={
                                commercialMetrics.average_ticket_period
                                    ? moneyLabel(
                                          commercialMetrics.average_ticket_period,
                                      )
                                    : '—'
                            }
                            detail="No mês atual da organização"
                            icon={DollarSign}
                        />
                        <Metric
                            label="Reativações pendentes"
                            value={commercialMetrics.pending_reactivation_tasks}
                            detail="Tarefas automáticas abertas"
                            icon={ListTodo}
                        />
                        <div className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] sm:col-span-2 xl:col-span-3">
                            <p className="text-sm text-muted-foreground">
                                Distribuição por faixa
                            </p>
                            <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(['A', 'B', 'C', 'D'] as const).map((tier) => (
                                    <div
                                        key={tier}
                                        className="rounded-xl bg-muted/50 px-4 py-3"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            Faixa {tier}
                                        </p>
                                        <p className="mt-1 text-xl font-semibold">
                                            {commercialMetrics.tier_distribution[
                                                tier
                                            ].toLocaleString('pt-BR')}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="border-b border-border/70 px-5 py-5 md:px-6">
                        <h2 className="font-semibold tracking-tight">
                            Clientes que precisam de atenção
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Alertas de compra e relacionamento com a próxima
                            ação sugerida.
                        </p>
                    </div>
                    {commercialAttention.length === 0 ? (
                        <p className="px-6 py-8 text-sm text-muted-foreground">
                            Nenhum alerta comercial ativo para sua carteira.
                        </p>
                    ) : (
                        <div className="divide-y divide-border/60">
                            {commercialAttention.map((item) => (
                                <article
                                    key={item.id}
                                    className="grid gap-4 px-5 py-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:px-6"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={showCompany.url(
                                                    item.company.id,
                                                )}
                                                className="font-semibold hover:text-primary hover:underline"
                                            >
                                                {item.company.name}
                                            </Link>
                                            {item.tier && (
                                                <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium">
                                                    Faixa {item.tier}
                                                </span>
                                            )}
                                            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                                {item.type ===
                                                'commercial_inactivity'
                                                    ? 'Sem compras recentes'
                                                    : 'Sem contato recente'}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {item.reason}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <span>
                                                Última compra:{' '}
                                                {dateLabel(
                                                    item.last_purchase_at,
                                                    commercialTimezone,
                                                )}
                                            </span>
                                            <span>
                                                Último contato:{' '}
                                                {dateLabel(
                                                    item.last_contact_at,
                                                    commercialTimezone,
                                                )}
                                            </span>
                                            <span>
                                                Responsável:{' '}
                                                {item.owner?.name ??
                                                    'Não definido'}
                                            </span>
                                            <span>
                                                Contato recomendado:{' '}
                                                {item.recommended_contact
                                                    ?.name ??
                                                    'Nenhum profissional definido'}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm font-medium">
                                            Ação sugerida:{' '}
                                            {item.suggested_action}
                                        </p>
                                    </div>
                                    {item.task?.can_view && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="w-fit"
                                        >
                                            <Link
                                                href={showTask.url(
                                                    item.task.id,
                                                )}
                                            >
                                                Abrir tarefa
                                            </Link>
                                        </Button>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </section>

                <section className="grid gap-5 xl:grid-cols-[1.05fr_0.95fr]">
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="flex items-center justify-between border-b border-border/70 px-5 py-5 md:px-6">
                            <div>
                                <h2 className="font-semibold tracking-tight">
                                    Empresas sem contato recente
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Sua carteira ordenada pelas interações mais
                                    antigas.
                                </p>
                            </div>
                            <Link
                                href={companiesIndex.url()}
                                className="hidden items-center gap-1.5 text-sm font-medium text-primary hover:underline sm:flex"
                            >
                                Ver carteira <ArrowRight className="size-4" />
                            </Link>
                        </div>
                        {attentionCompanies.length === 0 ? (
                            <EmptyState
                                title="Sua carteira está vazia"
                                detail="Cadastre uma empresa para começar a organizar seus relacionamentos."
                                action="Cadastrar empresa"
                                href={createCompany.url()}
                            />
                        ) : (
                            <div className="divide-y divide-border/60">
                                {attentionCompanies.map((company) => (
                                    <Link
                                        key={company.id}
                                        href={showCompany.url(company.id)}
                                        className="flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-muted/40 md:px-6"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {company.name}
                                            </p>
                                            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                <Clock3 className="size-3.5" />{' '}
                                                {dateLabel(
                                                    company.activities_max_occurred_at,
                                                )}
                                            </p>
                                        </div>
                                        <span className="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                                            {statusLabels[
                                                company.relationship_status
                                            ] ?? company.relationship_status}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="flex items-center justify-between border-b border-border/70 px-5 py-5 md:px-6">
                            <div>
                                <h2 className="font-semibold tracking-tight">
                                    Histórico recente
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    As últimas interações registradas pela
                                    equipe.
                                </p>
                            </div>
                            <MessageSquareText className="size-5 text-muted-foreground" />
                        </div>
                        {recentActivities.length === 0 ? (
                            <EmptyState
                                title="Ainda não há interações"
                                detail="Registre ligações, reuniões e acompanhamentos para manter o histórico completo."
                                action="Ver empresas"
                                href={companiesIndex.url()}
                            />
                        ) : (
                            <div className="divide-y divide-border/60">
                                {recentActivities.map((activity) => (
                                    <div
                                        key={activity.id}
                                        className="flex gap-3 px-5 py-4 md:px-6"
                                    >
                                        <div className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                                            <MessageSquareText className="size-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                <p className="text-sm font-medium">
                                                    {activity.company.name}
                                                </p>
                                                <span className="text-xs text-muted-foreground">
                                                    {dateLabel(
                                                        activity.occurred_at,
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                {activity.description}
                                            </p>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {typeLabels[activity.type] ??
                                                    'Interação'}
                                                {activity.contact
                                                    ? ` · ${activity.contact.name}`
                                                    : ''}{' '}
                                                · {activity.user.name}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
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
        <article className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02]">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="mt-3 text-3xl font-semibold tracking-tight">
                        {typeof value === 'number'
                            ? value.toLocaleString('pt-BR')
                            : value}
                    </p>
                </div>
                <span className="flex size-10 items-center justify-center rounded-xl bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                    <Icon className="size-5" />
                </span>
            </div>
            <p className="mt-3 text-xs text-muted-foreground">{detail}</p>
        </article>
    );
}

function EmptyState({
    title,
    detail,
    action,
    href,
}: {
    title: string;
    detail: string;
    action: string;
    href: string;
}) {
    return (
        <div className="px-6 py-10 text-center">
            <div className="mx-auto flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Building2 className="size-5" />
            </div>
            <p className="mt-3 text-sm font-medium">{title}</p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                {detail}
            </p>
            <Link
                href={href}
                className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            >
                {action}
                <ArrowRight className="size-4" />
            </Link>
        </div>
    );
}

Dashboard.layout = { breadcrumbs };
