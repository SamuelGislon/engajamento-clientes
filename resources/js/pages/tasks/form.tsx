import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { useMemo } from 'react';
import {
    index as tasksIndex,
    store as storeTask,
    update as updateTask,
} from '@/actions/App/Http/Controllers/TaskController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Contact = { id: number; name: string };
type Company = { id: number; name: string; contacts: Contact[] };
type Task = {
    id: number;
    title: string;
    description: string | null;
    type: string;
    priority: string;
    due_at: string;
    origin: string;
    company: { id: number; name: string } | null;
    contact: { id: number; name: string } | null;
    assignee: { id: number; name: string };
};

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

const priorities = [
    ['low', 'Baixa'],
    ['normal', 'Normal'],
    ['high', 'Alta'],
    ['urgent', 'Urgente'],
];

function localDateTime(value?: string): string {
    const date = value ? new Date(value) : new Date(Date.now() + 86_400_000);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 16);
}

export default function TaskForm({
    task,
    companies,
    members,
    canChangeRelations,
}: {
    task: Task | null;
    companies: Company[];
    members: Member[];
    canChangeRelations: boolean;
}) {
    const form = useForm({
        title: task?.title ?? '',
        description: task?.description ?? '',
        type: task?.type ?? 'call',
        priority: task?.priority ?? 'normal',
        due_at: localDateTime(task?.due_at),
        company_id: String(task?.company?.id ?? ''),
        contact_id: String(task?.contact?.id ?? ''),
        assigned_to: String(task?.assignee?.id ?? members[0]?.id ?? ''),
    });
    const selectedCompany = useMemo(
        () =>
            companies.find(
                (company) => String(company.id) === form.data.company_id,
            ),
        [companies, form.data.company_id],
    );

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            due_at: new Date(data.due_at).toISOString(),
            ...(canChangeRelations
                ? {}
                : {
                      company_id: undefined,
                      contact_id: undefined,
                      assigned_to: undefined,
                  }),
        }));

        if (task) {
            form.patch(updateTask.url(task.id));
        } else {
            form.post(storeTask.url());
        }
    }

    return (
        <>
            <Head title={task ? 'Editar atividade' : 'Nova atividade'} />
            <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex items-start gap-3">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label="Voltar para atividades"
                    >
                        <Link href={tasksIndex.url()}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <p className="text-sm font-medium text-primary">
                            Organização comercial
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            {task ? 'Editar atividade' : 'Nova atividade'}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Registre a próxima ação necessária no
                            relacionamento.
                        </p>
                    </div>
                </header>

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-5 rounded-2xl border border-border/70 bg-card p-5 md:p-7"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="title">Título</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            maxLength={180}
                            required
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Descrição</Label>
                        <textarea
                            id="description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            maxLength={5000}
                            rows={4}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Tipo</Label>
                            <select
                                id="type"
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value)
                                }
                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {taskTypes.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="priority">Prioridade</Label>
                            <select
                                id="priority"
                                value={form.data.priority}
                                onChange={(event) =>
                                    form.setData('priority', event.target.value)
                                }
                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {priorities.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.priority} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="due_at">Vencimento</Label>
                        <Input
                            id="due_at"
                            type="datetime-local"
                            value={form.data.due_at}
                            onChange={(event) =>
                                form.setData('due_at', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.due_at} />
                    </div>

                    {canChangeRelations && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="company_id">Empresa</Label>
                                <select
                                    id="company_id"
                                    value={form.data.company_id}
                                    onChange={(event) => {
                                        form.setData(
                                            'company_id',
                                            event.target.value,
                                        );
                                        form.setData('contact_id', '');
                                    }}
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        Sem empresa relacionada
                                    </option>
                                    {companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.company_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="contact_id">Profissional</Label>
                                <select
                                    id="contact_id"
                                    value={form.data.contact_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'contact_id',
                                            event.target.value,
                                        )
                                    }
                                    disabled={!selectedCompany}
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50"
                                >
                                    <option value="">
                                        Sem profissional relacionado
                                    </option>
                                    {selectedCompany?.contacts.map(
                                        (contact) => (
                                            <option
                                                key={contact.id}
                                                value={contact.id}
                                            >
                                                {contact.name}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <InputError message={form.errors.contact_id} />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="assigned_to">Responsável</Label>
                                <select
                                    id="assigned_to"
                                    value={form.data.assigned_to}
                                    onChange={(event) =>
                                        form.setData(
                                            'assigned_to',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {members.map((member) => (
                                        <option
                                            key={member.id}
                                            value={member.id}
                                        >
                                            {member.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.assigned_to} />
                            </div>
                        </div>
                    )}

                    <footer className="flex flex-wrap justify-end gap-2 border-t border-border/70 pt-5">
                        <Button asChild variant="outline">
                            <Link href={tasksIndex.url()}>Cancelar</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="gap-2"
                        >
                            <Save className="size-4" />{' '}
                            {form.processing ? 'Salvando…' : 'Salvar atividade'}
                        </Button>
                    </footer>
                </form>
            </main>
        </>
    );
}

TaskForm.layout = {
    breadcrumbs: [
        { title: 'Minhas atividades', href: tasksIndex.url() },
    ] satisfies BreadcrumbItem[],
};
