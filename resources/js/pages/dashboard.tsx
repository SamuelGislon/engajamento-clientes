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
} from 'lucide-react';
import {
    create as createCompany,
    index as companiesIndex,
    show as showCompany,
} from '@/actions/App/Http/Controllers/CompanyController';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
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

function dateLabel(value: string | null): string {
    if (!value) return 'Nenhuma interação registrada';
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}

export default function Dashboard({
    metrics,
    recentActivities,
    attentionCompanies,
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
    value: number;
    detail: string;
    icon: typeof Building2;
}) {
    return (
        <article className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02]">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="mt-3 text-3xl font-semibold tracking-tight">
                        {value.toLocaleString('pt-BR')}
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
