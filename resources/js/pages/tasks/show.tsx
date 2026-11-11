import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Building2, Check, Clock3, Pencil } from 'lucide-react';
import {
    cancel,
    complete,
    edit,
    index as tasksIndex,
} from '@/actions/App/Http/Controllers/TaskController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

type Task = {
    id: number;
    title: string;
    description: string | null;
    type: string;
    priority: string;
    status: string;
    origin: string;
    due_at: string;
    completed_at: string | null;
    completion_notes: string | null;
    company: { id: number; name: string } | null;
    contact: { id: number; name: string } | null;
    assignee: { id: number; name: string };
    creator: { id: number; name: string };
};
const statuses: Record<string, string> = {
    pending: 'Pendente',
    in_progress: 'Em andamento',
    completed: 'Concluída',
    postponed: 'Adiada',
    cancelled: 'Cancelada',
};
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Atividades', href: tasksIndex.url() },
];

export default function TaskShow({ task }: { task: Task }) {
    const isOpen = ['pending', 'in_progress', 'postponed'].includes(
        task.status,
    );
    return (
        <>
            <Head title={task.title} />
            <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-5 py-8 md:px-8">
                <Button asChild variant="ghost" className="w-fit">
                    <Link href={tasksIndex.url()}>
                        <ArrowLeft className="mr-2 size-4" /> Atividades
                    </Link>
                </Button>
                <article className="rounded-2xl border border-border/70 bg-card p-6 md:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-medium text-primary">
                                {task.origin === 'automatic'
                                    ? 'Etapa de jornada'
                                    : 'Atividade comercial'}
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                                {task.title}
                            </h1>
                        </div>
                        <span className="rounded-full bg-muted px-3 py-1 text-sm">
                            {statuses[task.status] ?? task.status}
                        </span>
                    </div>
                    {task.description && (
                        <p className="mt-5 text-sm leading-6 whitespace-pre-line text-muted-foreground">
                            {task.description}
                        </p>
                    )}
                    <dl className="mt-7 grid gap-5 border-t border-border/70 pt-6 sm:grid-cols-2">
                        <Detail
                            label="Vencimento"
                            value={new Intl.DateTimeFormat('pt-BR', {
                                dateStyle: 'medium',
                                timeStyle: 'short',
                            }).format(new Date(task.due_at))}
                        />
                        <Detail
                            label="Responsável"
                            value={task.assignee.name}
                        />
                        <Detail label="Prioridade" value={task.priority} />
                        <Detail label="Tipo" value={task.type} />
                        {task.contact && (
                            <Detail
                                label="Profissional"
                                value={task.contact.name}
                            />
                        )}
                        {task.creator && (
                            <Detail
                                label="Criada por"
                                value={task.creator.name}
                            />
                        )}
                    </dl>
                    {task.company && (
                        <Link
                            href={showCompany.url(task.company.id)}
                            className="mt-6 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
                        >
                            <Building2 className="size-4" /> {task.company.name}
                        </Link>
                    )}
                    {task.completion_notes && (
                        <p className="mt-6 rounded-lg bg-muted p-4 text-sm">
                            <strong>Registro de conclusão:</strong>{' '}
                            {task.completion_notes}
                        </p>
                    )}
                    <footer className="mt-7 flex flex-wrap gap-2 border-t border-border/70 pt-5">
                        {isOpen && (
                            <>
                                <Button
                                    onClick={() =>
                                        router.post(complete.url(task.id))
                                    }
                                >
                                    <Check className="mr-2 size-4" /> Concluir
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={edit.url(task.id)}>
                                        <Pencil className="mr-2 size-4" />{' '}
                                        Editar
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(cancel.url(task.id))
                                    }
                                >
                                    Cancelar atividade
                                </Button>
                            </>
                        )}
                        {task.completed_at && (
                            <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                                <Clock3 className="size-4" /> Concluída em{' '}
                                {new Intl.DateTimeFormat('pt-BR', {
                                    dateStyle: 'medium',
                                    timeStyle: 'short',
                                }).format(new Date(task.completed_at))}
                            </span>
                        )}
                    </footer>
                </article>
            </main>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1.5 text-sm">{value}</dd>
        </div>
    );
}

TaskShow.layout = { breadcrumbs };
