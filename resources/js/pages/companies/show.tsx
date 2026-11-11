import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CalendarClock,
    Check,
    Clock3,
    Mail,
    MessageSquareText,
    Phone,
    Plus,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    attachContact,
    index as companiesIndex,
    storeActivity,
    updateOwner,
} from '@/actions/App/Http/Controllers/CompanyController';
import { create as createContact } from '@/actions/App/Http/Controllers/ContactController';
import { store as startJourney } from '@/actions/App/Http/Controllers/JourneyEnrollmentController';
import {
    pause as pauseJourney,
    resume as resumeJourney,
    cancel as cancelJourney,
} from '@/actions/App/Http/Controllers/JourneyEnrollmentController';
import { show as showTask } from '@/actions/App/Http/Controllers/TaskController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Contact = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    job_title: string | null;
    pivot: { roles: string[]; is_primary: boolean; notes: string | null };
};
type Activity = {
    id: number;
    type: string;
    occurred_at: string;
    description: string;
    result: string | null;
    next_action: string | null;
    contact: { id: number; name: string } | null;
    user: { id: number; name: string };
};
type Company = {
    id: number;
    name: string;
    trade_name: string | null;
    legal_name: string | null;
    tax_id: string | null;
    segment: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    city: string | null;
    state: string | null;
    address: string | null;
    relationship_status: string;
    lead_source: string | null;
    owner_user_id: number | null;
    owner: { id: number; name: string } | null;
    notes: string | null;
    contacts: Contact[];
    activities: Activity[];
};
type AttachableContact = { id: number; name: string; job_title: string | null };
type TaskItem = {
    id: number;
    title: string;
    status: string;
    due_at: string;
    origin: string;
    assignee: { id: number; name: string };
    journeyEnrollment?: { template: { name: string } } | null;
};
type Journey = {
    id: number;
    status: string;
    template: { id: number; name: string };
    tasks: {
        id: number;
        title: string;
        status: string;
        due_at: string;
        journey_step_execution?: { step: { title: string } } | null;
    }[];
};
type JourneyOption = { id: number; name: string };

const activityTypes = [
    ['call', 'Ligação'],
    ['message', 'Mensagem'],
    ['whatsapp', 'WhatsApp'],
    ['email', 'E-mail'],
    ['meeting', 'Reunião'],
    ['visit', 'Visita presencial'],
    ['presentation', 'Apresentação comercial'],
    ['material', 'Envio de material'],
    ['quote_follow_up', 'Acompanhamento de orçamento'],
    ['other', 'Outro'],
];
const roleOptions = [
    ['owner', 'Proprietário'],
    ['partner', 'Sócio'],
    ['budget_manager', 'Responsável por orçamentos'],
    ['buyer', 'Comprador'],
    ['decision_maker', 'Decisor'],
    ['influencer', 'Influenciador'],
    ['specifier', 'Especificador'],
    ['finance', 'Financeiro'],
    ['operations', 'Contato operacional'],
    ['other', 'Outro'],
];
const statusLabels: Record<string, string> = {
    prospect: 'Prospecto',
    customer: 'Cliente',
    inactive: 'Inativo',
    partner: 'Parceiro',
};
const statusStyles: Record<string, string> = {
    prospect: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
    customer:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
    inactive:
        'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    partner:
        'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-200',
};
const typeLabels = Object.fromEntries(activityTypes);

function displayDate(
    value: string | null | undefined,
    includeTime = false,
): string {
    if (!value) return 'Ainda não registrado';
    return new Intl.DateTimeFormat(
        'pt-BR',
        includeTime
            ? { dateStyle: 'medium', timeStyle: 'short' }
            : { dateStyle: 'medium' },
    ).format(new Date(value));
}

function localDateTime(): string {
    const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000);
    return date.toISOString().slice(0, 16);
}

export default function CompanyShow({
    company,
    attachableContacts,
    members,
    journeys,
    availableJourneys,
    tasks,
}: {
    company: Company;
    attachableContacts: AttachableContact[];
    members: Member[];
    journeys: Journey[];
    availableJourneys: JourneyOption[];
    tasks: TaskItem[];
}) {
    const [owner, setOwner] = useState(String(company.owner_user_id ?? ''));
    const [newRoles, setNewRoles] = useState<string[]>([]);
    const activityForm = useForm({
        contact_id: '',
        type: 'call',
        occurred_at: localDateTime(),
        description: '',
        result: '',
        next_action: '',
    });
    const attachForm = useForm({
        contact_id: '',
        roles: newRoles,
        is_primary: false,
        relationship_notes: '',
    });
    const journeyForm = useForm({
        journey_template_id: String(availableJourneys[0]?.id ?? ''),
    });
    function submitActivity(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        activityForm.post(storeActivity.url(company.id), {
            onSuccess: () =>
                activityForm.reset(
                    'contact_id',
                    'description',
                    'result',
                    'next_action',
                ),
        });
    }

    function toggleRole(value: string) {
        const nextRoles = newRoles.includes(value)
            ? newRoles.filter((role) => role !== value)
            : [...newRoles, value];
        setNewRoles(nextRoles);
        attachForm.setData('roles', nextRoles);
    }

    function submitAttachment(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        attachForm.post(attachContact.url(company.id), {
            onSuccess: () => {
                attachForm.reset();
                setNewRoles([]);
            },
        });
    }

    function saveOwner(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.patch(updateOwner.url(company.id), {
            owner_user_id: Number(owner),
        });
    }

    function submitJourney(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        journeyForm.post(startJourney.url(company.id));
    }

    return (
        <>
            <Head title={company.trade_name || company.name} />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <Link
                    href={companiesIndex.url()}
                    className="inline-flex w-fit items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Voltar para empresas
                </Link>
                <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                    <div className="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                        <div className="flex min-w-0 items-start gap-4">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                                <Building2 className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-3xl font-semibold tracking-tight">
                                        {company.trade_name || company.name}
                                    </h1>
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusStyles[company.relationship_status] ?? 'bg-muted text-muted-foreground'}`}
                                    >
                                        {statusLabels[
                                            company.relationship_status
                                        ] ?? company.relationship_status}
                                    </span>
                                </div>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {[
                                        company.segment,
                                        company.city && company.state
                                            ? `${company.city}, ${company.state}`
                                            : company.city || company.state,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') ||
                                        'Segmento não informado'}
                                </p>
                                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                    <span className="inline-flex items-center gap-2 text-muted-foreground">
                                        <UserRound className="size-4" />
                                        {company.owner?.name ??
                                            'Sem responsável'}
                                    </span>
                                    <span className="inline-flex items-center gap-2 text-muted-foreground">
                                        <Clock3 className="size-4" />
                                        Último contato:{' '}
                                        {displayDate(
                                            company.activities[0]?.occurred_at,
                                        )}
                                    </span>
                                    <span className="inline-flex items-center gap-2 text-muted-foreground">
                                        <CalendarClock className="size-4" />
                                        Última compra: disponível na Fase 3
                                    </span>
                                </div>
                            </div>
                        </div>
                        <form
                            onSubmit={saveOwner}
                            className="flex w-full flex-col gap-2 sm:flex-row xl:w-auto"
                        >
                            <select
                                aria-label="Vendedor responsável"
                                value={owner}
                                onChange={(event) =>
                                    setOwner(event.target.value)
                                }
                                className="h-10 min-w-56 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">
                                    Sem responsável definido
                                </option>
                                {members.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                            <Button
                                type="submit"
                                variant="outline"
                                className="h-10 rounded-lg"
                            >
                                Atualizar responsável
                            </Button>
                        </form>
                    </div>
                </section>

                <section
                    id="activities"
                    className="grid scroll-mt-6 gap-5 xl:grid-cols-2"
                >
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 px-5 py-5 md:px-6">
                            <div>
                                <h2 className="font-semibold">
                                    Jornadas comerciais
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Etapas automáticas e estado de cada
                                    inscrição.
                                </p>
                            </div>
                            {company.relationship_status === 'prospect' &&
                                availableJourneys.length > 0 && (
                                    <form
                                        onSubmit={submitJourney}
                                        className="flex flex-wrap gap-2"
                                    >
                                        <select
                                            aria-label="Modelo de jornada"
                                            value={
                                                journeyForm.data
                                                    .journey_template_id
                                            }
                                            onChange={(event) =>
                                                journeyForm.setData(
                                                    'journey_template_id',
                                                    event.target.value,
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                        >
                                            {availableJourneys.map(
                                                (journey) => (
                                                    <option
                                                        key={journey.id}
                                                        value={journey.id}
                                                    >
                                                        {journey.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <Button
                                            size="sm"
                                            type="submit"
                                            disabled={journeyForm.processing}
                                        >
                                            Iniciar
                                        </Button>
                                    </form>
                                )}
                        </div>
                        {journeys.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-muted-foreground">
                                Nenhuma jornada iniciada para esta empresa.
                            </p>
                        ) : (
                            <div className="divide-y divide-border/60">
                                {journeys.map((journey) => (
                                    <article
                                        key={journey.id}
                                        className="px-5 py-4 md:px-6"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <h3 className="text-sm font-medium">
                                                    {journey.template.name}
                                                </h3>
                                                <p className="mt-1 text-xs text-muted-foreground capitalize">
                                                    {journey.status === 'active'
                                                        ? 'Em andamento'
                                                        : journey.status ===
                                                            'paused'
                                                          ? 'Pausada'
                                                          : journey.status ===
                                                              'completed'
                                                            ? 'Concluída'
                                                            : 'Cancelada'}
                                                </p>
                                            </div>
                                            <div className="flex gap-1">
                                                {journey.status ===
                                                    'active' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                pauseJourney.url(
                                                                    journey.id,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Pausar
                                                    </Button>
                                                )}
                                                {journey.status ===
                                                    'paused' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                resumeJourney.url(
                                                                    journey.id,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Retomar
                                                    </Button>
                                                )}
                                                {['active', 'paused'].includes(
                                                    journey.status,
                                                ) && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(
                                                                cancelJourney.url(
                                                                    journey.id,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Cancelar
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                        <ol className="mt-3 grid gap-2">
                                            {journey.tasks.map((task) => (
                                                <li key={task.id}>
                                                    <Link
                                                        href={showTask.url(
                                                            task.id,
                                                        )}
                                                        className="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm hover:bg-muted"
                                                    >
                                                        <span>
                                                            {task
                                                                .journey_step_execution
                                                                ?.step?.title ??
                                                                task.title}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {new Intl.DateTimeFormat(
                                                                'pt-BR',
                                                                {
                                                                    dateStyle:
                                                                        'medium',
                                                                },
                                                            ).format(
                                                                new Date(
                                                                    task.due_at,
                                                                ),
                                                            )}{' '}
                                                            ·{' '}
                                                            {task.status ===
                                                            'completed'
                                                                ? 'Concluída'
                                                                : task.status ===
                                                                    'cancelled'
                                                                  ? 'Cancelada'
                                                                  : 'Aberta'}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ol>
                                    </article>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                        <div className="border-b border-border/70 px-5 py-5 md:px-6">
                            <h2 className="font-semibold">
                                Atividades da empresa
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Tarefas manuais e automáticas associadas.
                            </p>
                        </div>
                        {tasks.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-muted-foreground">
                                Nenhuma atividade cadastrada.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border/60">
                                {tasks.map((task) => (
                                    <li key={task.id}>
                                        <Link
                                            href={showTask.url(task.id)}
                                            className="flex items-center justify-between gap-3 px-5 py-4 hover:bg-muted/40 md:px-6"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {task.title}
                                                </span>
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {task.assignee.name} ·{' '}
                                                    {task.origin === 'automatic'
                                                        ? 'Jornada'
                                                        : 'Manual'}
                                                </span>
                                            </span>
                                            <time className="shrink-0 text-xs text-muted-foreground">
                                                {new Intl.DateTimeFormat(
                                                    'pt-BR',
                                                    {
                                                        dateStyle: 'medium',
                                                        timeStyle: 'short',
                                                    },
                                                ).format(new Date(task.due_at))}
                                            </time>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[1.1fr_0.9fr]">
                    <div className="flex flex-col gap-5">
                        <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                            <div className="flex flex-col gap-3 border-b border-border/70 px-5 py-5 sm:flex-row sm:items-center sm:justify-between md:px-6">
                                <div>
                                    <h2 className="flex items-center gap-2 font-semibold">
                                        <UsersRound className="size-4 text-primary" />{' '}
                                        Profissionais vinculados
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Quem participa das conversas comerciais
                                        desta empresa.
                                    </p>
                                </div>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-9 gap-2 self-start rounded-lg"
                                >
                                    <Link
                                        href={createContact.url({
                                            query: { company_id: company.id },
                                        })}
                                    >
                                        <Plus className="size-4" /> Novo
                                        profissional
                                    </Link>
                                </Button>
                            </div>
                            {company.contacts.length === 0 ? (
                                <div className="px-6 py-8 text-sm text-muted-foreground">
                                    Nenhum profissional vinculado a esta
                                    empresa.
                                </div>
                            ) : (
                                <div className="divide-y divide-border/60">
                                    {company.contacts.map((contact) => (
                                        <article
                                            key={contact.id}
                                            className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between md:px-6"
                                        >
                                            <div className="flex min-w-0 items-start gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    <UserRound className="size-4" />
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="font-medium">
                                                            {contact.name}
                                                        </p>
                                                        {contact.pivot
                                                            .is_primary && (
                                                            <span className="rounded-full bg-[#edf4ef] px-2 py-0.5 text-[11px] font-medium text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                                                                Contato
                                                                principal
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {contact.job_title ||
                                                            'Cargo não informado'}
                                                    </p>
                                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                        {contact.email && (
                                                            <span className="inline-flex items-center gap-1.5">
                                                                <Mail className="size-3.5" />
                                                                {contact.email}
                                                            </span>
                                                        )}
                                                        {(contact.whatsapp ||
                                                            contact.phone) && (
                                                            <span className="inline-flex items-center gap-1.5">
                                                                <Phone className="size-3.5" />
                                                                {contact.whatsapp ||
                                                                    contact.phone}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap gap-1.5 sm:justify-end">
                                                {contact.pivot.roles?.map(
                                                    (role) => (
                                                        <span
                                                            key={role}
                                                            className="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground"
                                                        >
                                                            {roleOptions.find(
                                                                ([value]) =>
                                                                    value ===
                                                                    role,
                                                            )?.[1] ?? role}
                                                        </span>
                                                    ),
                                                )}
                                            </div>
                                            {contact.pivot.notes && (
                                                <p className="text-xs text-muted-foreground sm:max-w-48">
                                                    {contact.pivot.notes}
                                                </p>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            )}
                            {attachableContacts.length > 0 && (
                                <details className="border-t border-border/70 px-5 py-4 md:px-6">
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Vincular profissional já cadastrado
                                    </summary>
                                    <form
                                        onSubmit={submitAttachment}
                                        className="mt-4 grid gap-4"
                                    >
                                        <Field
                                            label="Profissional"
                                            id="attach-contact"
                                            error={attachForm.errors.contact_id}
                                        >
                                            <select
                                                id="attach-contact"
                                                value={
                                                    attachForm.data.contact_id
                                                }
                                                onChange={(event) =>
                                                    attachForm.setData(
                                                        'contact_id',
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="">
                                                    Selecione
                                                </option>
                                                {attachableContacts.map(
                                                    (contact) => (
                                                        <option
                                                            key={contact.id}
                                                            value={contact.id}
                                                        >
                                                            {contact.name}
                                                            {contact.job_title
                                                                ? ` · ${contact.job_title}`
                                                                : ''}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>
                                        <div>
                                            <Label>
                                                Responsabilidades nesta empresa
                                            </Label>
                                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                {roleOptions.map(
                                                    ([value, label]) => (
                                                        <label
                                                            key={value}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={newRoles.includes(
                                                                    value,
                                                                )}
                                                                onChange={() =>
                                                                    toggleRole(
                                                                        value,
                                                                    )
                                                                }
                                                                className="size-4 accent-[#315c4c]"
                                                            />
                                                            {label}
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                            <InputError
                                                message={
                                                    attachForm.errors.roles
                                                }
                                            />
                                        </div>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    attachForm.data.is_primary
                                                }
                                                onChange={(event) =>
                                                    attachForm.setData(
                                                        'is_primary',
                                                        event.target.checked,
                                                    )
                                                }
                                                className="size-4 accent-[#315c4c]"
                                            />
                                            Contato principal
                                        </label>
                                        <Field
                                            label="Observação do vínculo"
                                            id="relationship-notes"
                                            error={
                                                attachForm.errors
                                                    .relationship_notes
                                            }
                                        >
                                            <Input
                                                id="relationship-notes"
                                                value={
                                                    attachForm.data
                                                        .relationship_notes
                                                }
                                                onChange={(event) =>
                                                    attachForm.setData(
                                                        'relationship_notes',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={attachForm.processing}
                                            className="w-fit gap-2 rounded-lg"
                                        >
                                            {attachForm.processing && (
                                                <Spinner />
                                            )}{' '}
                                            Vincular profissional
                                        </Button>
                                    </form>
                                </details>
                            )}
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                            <div className="border-b border-border/70 px-5 py-5 md:px-6">
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <MessageSquareText className="size-4 text-primary" />{' '}
                                    Histórico de relacionamento
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Interações registradas manualmente pela
                                    equipe.
                                </p>
                            </div>
                            {company.activities.length === 0 ? (
                                <div className="px-6 py-8 text-sm text-muted-foreground">
                                    Registre a primeira interação para iniciar o
                                    histórico desta empresa.
                                </div>
                            ) : (
                                <div className="divide-y divide-border/60">
                                    {company.activities.map((activity) => (
                                        <article
                                            key={activity.id}
                                            className="flex gap-3 px-5 py-4 md:px-6"
                                        >
                                            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                                                <MessageSquareText className="size-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                    <p className="text-sm font-medium">
                                                        {typeLabels[
                                                            activity.type
                                                        ] ?? 'Interação'}
                                                        {activity.contact
                                                            ? ` · ${activity.contact.name}`
                                                            : ''}
                                                    </p>
                                                    <time className="text-xs text-muted-foreground">
                                                        {displayDate(
                                                            activity.occurred_at,
                                                            true,
                                                        )}
                                                    </time>
                                                </div>
                                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-muted-foreground">
                                                    {activity.description}
                                                </p>
                                                {activity.result && (
                                                    <p className="mt-2 text-sm">
                                                        <span className="font-medium">
                                                            Resultado:
                                                        </span>{' '}
                                                        <span className="text-muted-foreground">
                                                            {activity.result}
                                                        </span>
                                                    </p>
                                                )}
                                                {activity.next_action && (
                                                    <p className="mt-2 inline-flex items-start gap-1.5 text-sm text-[#315c4c] dark:text-emerald-200">
                                                        <CalendarClock className="mt-0.5 size-4 shrink-0" />
                                                        <span>
                                                            <span className="font-medium">
                                                                Próxima ação
                                                                sugerida:
                                                            </span>{' '}
                                                            {
                                                                activity.next_action
                                                            }
                                                        </span>
                                                    </p>
                                                )}
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    Registrado por{' '}
                                                    {activity.user.name}
                                                </p>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>

                    <div className="flex flex-col gap-5">
                        <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-6">
                            <h2 className="font-semibold">
                                Registrar interação
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Adicione o contexto da conversa e o próximo
                                passo recomendado.
                            </p>
                            <form
                                onSubmit={submitActivity}
                                className="mt-5 grid gap-4"
                            >
                                <Field
                                    label="Tipo de interação"
                                    id="activity-type"
                                    error={activityForm.errors.type}
                                >
                                    <select
                                        id="activity-type"
                                        value={activityForm.data.type}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'type',
                                                event.target.value,
                                            )
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        {activityTypes.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    label="Profissional"
                                    id="activity-contact"
                                    error={activityForm.errors.contact_id}
                                >
                                    <select
                                        id="activity-contact"
                                        value={activityForm.data.contact_id}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'contact_id',
                                                event.target.value,
                                            )
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="">
                                            Sem pessoa específica
                                        </option>
                                        {company.contacts.map((contact) => (
                                            <option
                                                key={contact.id}
                                                value={contact.id}
                                            >
                                                {contact.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    label="Data e hora"
                                    id="occurred_at"
                                    error={activityForm.errors.occurred_at}
                                >
                                    <Input
                                        id="occurred_at"
                                        type="datetime-local"
                                        value={activityForm.data.occurred_at}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'occurred_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Descrição"
                                    id="description"
                                    error={activityForm.errors.description}
                                    required
                                >
                                    <textarea
                                        id="description"
                                        required
                                        value={activityForm.data.description}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        rows={4}
                                        placeholder="O que foi conversado?"
                                        className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </Field>
                                <Field
                                    label="Resultado"
                                    id="result"
                                    error={activityForm.errors.result}
                                >
                                    <textarea
                                        id="result"
                                        value={activityForm.data.result}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'result',
                                                event.target.value,
                                            )
                                        }
                                        rows={2}
                                        className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        placeholder="Resposta ou resultado da interação"
                                    />
                                </Field>
                                <Field
                                    label="Próxima ação sugerida"
                                    id="next_action"
                                    error={activityForm.errors.next_action}
                                >
                                    <textarea
                                        id="next_action"
                                        value={activityForm.data.next_action}
                                        onChange={(event) =>
                                            activityForm.setData(
                                                'next_action',
                                                event.target.value,
                                            )
                                        }
                                        rows={2}
                                        className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        placeholder="Ex.: Retomar contato em 15 dias"
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    disabled={activityForm.processing}
                                    className="gap-2 rounded-lg"
                                >
                                    {activityForm.processing ? (
                                        <Spinner />
                                    ) : (
                                        <Check className="size-4" />
                                    )}{' '}
                                    Salvar interação
                                </Button>
                            </form>
                        </section>

                        <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-6">
                            <h2 className="font-semibold">Visão geral</h2>
                            <div className="mt-4 grid gap-4 text-sm">
                                <Overview
                                    label="Razão social"
                                    value={company.legal_name}
                                />
                                <Overview label="CNPJ" value={company.tax_id} />
                                <Overview
                                    label="Telefone"
                                    value={company.phone}
                                />
                                <Overview
                                    label="E-mail"
                                    value={company.email}
                                />
                                <Overview
                                    label="Site"
                                    value={company.website}
                                    link
                                />
                                <Overview
                                    label="Origem"
                                    value={company.lead_source}
                                />
                                <Overview
                                    label="Endereço"
                                    value={[
                                        company.address,
                                        company.city,
                                        company.state,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                />
                                {company.notes && (
                                    <div className="border-t border-border/60 pt-4">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Anotações
                                        </p>
                                        <p className="mt-1.5 leading-6 whitespace-pre-line">
                                            {company.notes}
                                        </p>
                                    </div>
                                )}
                                <div className="border-t border-border/60 pt-4">
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Criada em
                                    </p>
                                    <p className="mt-1.5">
                                        {displayDate(
                                            (
                                                company as Company & {
                                                    created_at?: string;
                                                }
                                            ).created_at,
                                        )}
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </main>
        </>
    );
}

function Field({
    label,
    id,
    error,
    required = false,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Overview({
    label,
    value,
    link = false,
}: {
    label: string;
    value: string | null | undefined;
    link?: boolean;
}) {
    if (!value) return null;
    return (
        <div>
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            {link ? (
                <a
                    href={value}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-1.5 inline-block break-all text-primary hover:underline"
                >
                    {value}
                </a>
            ) : (
                <p className="mt-1.5 break-words">{value}</p>
            )}
        </div>
    );
}

CompanyShow.layout = {
    breadcrumbs: [
        { title: 'Empresas', href: companiesIndex.url() },
    ] satisfies BreadcrumbItem[],
};
