import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowUpRight, Check, Clock3, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import {
    cancel,
    complete,
    create as createTask,
    edit,
    index as tasksIndex,
    postpone,
} from '@/actions/App/Http/Controllers/TaskController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Task = {
    id: number;
    title: string;
    description: string | null;
    status: string;
    priority: string;
    due_at: string;
    origin: string;
    company: { id: number; name: string } | null;
    contact: { id: number; name: string } | null;
    assignee: { id: number; name: string };
};

type PageLink = { url: string | null; label: string; active: boolean };

type TaskPage = {
    data: Task[];
    links: PageLink[];
    total: number;
    from: number | null;
    to: number | null;
};

const statusLabels: Record<string, string> = {
    pending: 'Pendente',
    in_progress: 'Em andamento',
    completed: 'Concluída',
    postponed: 'Adiada',
    cancelled: 'Cancelada',
};

const priorityLabels: Record<string, string> = {
    low: 'Baixa',
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
};

const statusStyles: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    in_progress: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
    completed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
    postponed:
        'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    cancelled: 'bg-muted text-muted-foreground',
};

function dateLabel(value: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function localDateTime(value: string): string {
    const date = new Date(value);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 16);
}

export default function TasksIndex({
    tasks,
    filters,
    metrics,
}: {
    tasks: TaskPage;
    filters: {
        status?: string;
        priority?: string;
        company_id?: number;
        from?: string;
        to?: string;
    };
    metrics: {
        today: number;
        overdue: number;
        upcoming: number;
        completed: number;
    };
}) {
    const form = useForm({
        status: filters.status ?? '',
        priority: filters.priority ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    function submitFilters(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get(tasksIndex.url(), { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Minhas atividades" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-sm font-medium text-primary">
                            Organização comercial
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            Minhas atividades
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Priorize os próximos contatos e mantenha cada
                            compromisso em dia.
                        </p>
                    </div>
                    <Button asChild className="h-10 gap-2 rounded-lg px-4">
                        <Link href={createTask.url()}>
                            <Plus className="size-4" /> Nova atividade
                        </Link>
                    </Button>
                </header>

                <section
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Resumo das atividades"
                >
                    <MetricCard label="Para hoje" value={metrics.today} />
                    <MetricCard
                        label="Atrasadas"
                        value={metrics.overdue}
                        emphasis={metrics.overdue > 0}
                    />
                    <MetricCard label="Próximas" value={metrics.upcoming} />
                    <MetricCard
                        label="Concluídas nos últimos 30 dias"
                        value={metrics.completed}
                    />
                </section>

                <form
                    onSubmit={submitFilters}
                    className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]"
                >
                    <select
                        aria-label="Filtrar por status"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os status</option>
                        {Object.entries(statusLabels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por prioridade"
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as prioridades</option>
                        {Object.entries(priorityLabels).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                    <Input
                        aria-label="Vencimento inicial"
                        type="date"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        aria-label="Vencimento final"
                        type="date"
                        value={form.data.to}
                        onChange={(event) =>
                            form.setData('to', event.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        className="gap-2"
                        disabled={form.processing}
                    >
                        <Search className="size-4" /> Filtrar
                    </Button>
                </form>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="flex items-center justify-between border-b border-border/70 px-5 py-4">
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Atividades
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {tasks.from ?? 0}–{tasks.to ?? 0} de{' '}
                                {tasks.total}
                            </p>
                        </div>
                        <Clock3 className="size-5 text-muted-foreground" />
                    </div>
                    {tasks.data.length === 0 ? (
                        <div className="px-6 py-14 text-center">
                            <div className="mx-auto flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <Check className="size-5" />
                            </div>
                            <p className="mt-3 font-medium">
                                Nenhuma atividade encontrada
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Ajuste os filtros ou crie uma nova atividade.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {tasks.data.map((task) => (
                                <TaskRow key={task.id} task={task} />
                            ))}
                        </ul>
                    )}
                    <nav
                        className="flex flex-wrap justify-center gap-1 border-t border-border/70 px-4 py-3"
                        aria-label="Paginação das atividades"
                    >
                        {tasks.links.map((link, index) => {
                            const label = link.label
                                .replace('&laquo;', '‹')
                                .replace('&raquo;', '›');

                            return link.url ? (
                                <Link
                                    key={`${label}-${index}`}
                                    href={link.url}
                                    className={`rounded-md px-3 py-1.5 text-xs ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                >
                                    {label}
                                </Link>
                            ) : (
                                <span
                                    key={`${label}-${index}`}
                                    className="cursor-not-allowed rounded-md px-3 py-1.5 text-xs opacity-40"
                                >
                                    {label}
                                </span>
                            );
                        })}
                    </nav>
                </section>
            </main>
        </>
    );
}

function MetricCard({
    label,
    value,
    emphasis = false,
}: {
    label: string;
    value: number;
    emphasis?: boolean;
}) {
    return (
        <div
            className={`rounded-2xl border border-border/70 bg-card p-5 ${emphasis ? 'border-amber-300 dark:border-amber-900' : ''}`}
        >
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={`mt-2 text-3xl font-semibold tracking-tight ${emphasis ? 'text-amber-700 dark:text-amber-300' : ''}`}
            >
                {value}
            </p>
        </div>
    );
}

function TaskRow({ task }: { task: Task }) {
    const [dueAt, setDueAt] = useState(localDateTime(task.due_at));
    const open = ['pending', 'in_progress', 'postponed'].includes(task.status);

    return (
        <li className="flex flex-col gap-4 px-5 py-5 md:px-6">
            <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-medium">{task.title}</h3>
                        <span
                            className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusStyles[task.status] ?? 'bg-muted text-muted-foreground'}`}
                        >
                            {statusLabels[task.status] ?? task.status}
                        </span>
                        <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                            {priorityLabels[task.priority] ?? task.priority}
                        </span>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {task.company?.name ?? 'Sem empresa'}
                        {task.contact ? ` · ${task.contact.name}` : ''}
                        {' · '}
                        {task.assignee.name}
                        {' · '}
                        {task.origin === 'automatic' ? 'Jornada' : 'Manual'}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Vencimento: {dateLabel(task.due_at)}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {open && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            onClick={() => router.post(complete.url(task.id))}
                        >
                            <Check className="size-4" /> Concluir
                        </Button>
                    )}
                    <Button asChild variant="outline" size="sm">
                        <Link href={edit.url(task.id)}>Editar</Link>
                    </Button>
                    {task.company && (
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="gap-1.5"
                        >
                            <Link href={showCompany.url(task.company.id)}>
                                Empresa <ArrowUpRight className="size-3.5" />
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
            {open && (
                <div className="flex flex-wrap items-end gap-2 border-t border-border/60 pt-3">
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        Adiar até
                        <Input
                            type="datetime-local"
                            value={dueAt}
                            onChange={(event) => setDueAt(event.target.value)}
                            className="h-9 w-52"
                        />
                    </label>
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={!dueAt}
                        onClick={() =>
                            router.patch(postpone.url(task.id), {
                                due_at: new Date(dueAt).toISOString(),
                            })
                        }
                    >
                        Adiar
                    </Button>
                    {task.company && (
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={`${showCompany.url(task.company.id)}#activities`}
                            >
                                Registrar interação
                            </Link>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => router.post(cancel.url(task.id))}
                    >
                        Cancelar
                    </Button>
                </div>
            )}
        </li>
    );
}

TasksIndex.layout = {
    breadcrumbs: [
        { title: 'Minhas atividades', href: tasksIndex.url() },
    ] satisfies BreadcrumbItem[],
};
