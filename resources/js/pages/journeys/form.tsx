import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-react';
import {
    index as journeysIndex,
    store,
    update,
} from '@/actions/App/Http/Controllers/JourneyTemplateController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';

type Step = {
    title: string;
    description: string;
    delay_days: number;
    task_title: string;
    task_description: string;
    task_type: string;
    task_priority: string;
};
type Template = {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    steps: (Step & { id: number })[];
};
const blankStep = (): Step => ({
    title: '',
    description: '',
    delay_days: 1,
    task_title: '',
    task_description: '',
    task_type: 'follow_up',
    task_priority: 'normal',
});

export default function JourneyForm({
    template,
    canEditSteps,
}: {
    template: Template | null;
    canEditSteps: boolean;
}) {
    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        is_active: template?.is_active ?? true,
        is_default: template?.is_default ?? false,
        steps: template?.steps.map(({ id: _id, ...step }) => step) ?? [
            blankStep(),
        ],
    });
    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!canEditSteps) {
            form.transform(({ steps: _steps, ...data }) => data);
        }
        if (template) form.patch(update.url(template.id));
        else form.post(store.url());
    }

    function updateStep(
        index: number,
        key: keyof Step,
        value: string | number,
    ) {
        form.setData(
            'steps',
            form.data.steps.map((step, stepIndex) =>
                stepIndex === index ? { ...step, [key]: value } : step,
            ),
        );
    }

    return (
        <>
            <Head title={template ? 'Editar jornada' : 'Nova jornada'} />
            <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex items-start gap-3">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label="Voltar para jornadas"
                    >
                        <Link href={journeysIndex.url()}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <div>
                        <p className="text-sm font-medium text-primary">
                            Configuração
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            {template ? 'Editar jornada' : 'Nova jornada'}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Cada etapa gera uma tarefa após o atraso
                            configurado.
                        </p>
                    </div>
                </header>
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-5 rounded-2xl border border-border/70 bg-card p-5 md:p-7"
                >
                    <Field label="Nome do modelo" error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            maxLength={180}
                        />
                    </Field>
                    <Field label="Descrição" error={form.errors.description}>
                        <textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={3}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </Field>
                    <div className="flex flex-wrap gap-x-6 gap-y-3 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) =>
                                    form.setData(
                                        'is_active',
                                        event.target.checked,
                                    )
                                }
                            />{' '}
                            Modelo ativo
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_default}
                                onChange={(event) =>
                                    form.setData(
                                        'is_default',
                                        event.target.checked,
                                    )
                                }
                            />{' '}
                            Jornada padrão para novos prospectos
                        </label>
                    </div>
                    <InputError message={form.errors.is_default} />
                    {canEditSteps ? (
                        <section className="grid gap-4 border-t border-border/70 pt-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold">
                                        Etapas e tarefas
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Use {'{company}'} no título da tarefa
                                        para inserir o nome da empresa.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData('steps', [
                                            ...form.data.steps,
                                            blankStep(),
                                        ])
                                    }
                                >
                                    <Plus className="mr-2 size-4" /> Etapa
                                </Button>
                            </div>
                            {form.data.steps.map((step, index) => (
                                <article
                                    key={index}
                                    className="grid gap-4 rounded-xl border border-border/70 p-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <h3 className="font-medium">
                                            Etapa {index + 1}
                                        </h3>
                                        {form.data.steps.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remover etapa ${index + 1}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'steps',
                                                        form.data.steps.filter(
                                                            (_, stepIndex) =>
                                                                stepIndex !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                                        <Field
                                            label="Nome da etapa"
                                            error={
                                                form.errors[
                                                    `steps.${index}.title`
                                                ]
                                            }
                                        >
                                            <Input
                                                value={step.title}
                                                onChange={(event) =>
                                                    updateStep(
                                                        index,
                                                        'title',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </Field>
                                        <Field
                                            label="Atraso em dias"
                                            error={
                                                form.errors[
                                                    `steps.${index}.delay_days`
                                                ]
                                            }
                                        >
                                            <Input
                                                type="number"
                                                min={0}
                                                max={3650}
                                                value={step.delay_days}
                                                onChange={(event) =>
                                                    updateStep(
                                                        index,
                                                        'delay_days',
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                required
                                            />
                                        </Field>
                                    </div>
                                    <Field label="Descrição da etapa">
                                        <textarea
                                            value={step.description}
                                            onChange={(event) =>
                                                updateStep(
                                                    index,
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            rows={2}
                                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </Field>
                                    <Field
                                        label="Título da tarefa"
                                        error={
                                            form.errors[
                                                `steps.${index}.task_title`
                                            ]
                                        }
                                    >
                                        <Input
                                            value={step.task_title}
                                            onChange={(event) =>
                                                updateStep(
                                                    index,
                                                    'task_title',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </Field>
                                    <Field label="Descrição da tarefa">
                                        <textarea
                                            value={step.task_description}
                                            onChange={(event) =>
                                                updateStep(
                                                    index,
                                                    'task_description',
                                                    event.target.value,
                                                )
                                            }
                                            rows={2}
                                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </Field>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Tipo de tarefa">
                                            <select
                                                value={step.task_type}
                                                onChange={(event) =>
                                                    updateStep(
                                                        index,
                                                        'task_type',
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="first_contact">
                                                    Primeiro contato
                                                </option>
                                                <option value="call">
                                                    Ligação
                                                </option>
                                                <option value="whatsapp">
                                                    WhatsApp
                                                </option>
                                                <option value="email">
                                                    E-mail
                                                </option>
                                                <option value="meeting">
                                                    Reunião
                                                </option>
                                                <option value="visit">
                                                    Visita
                                                </option>
                                                <option value="prospecting">
                                                    Prospecção
                                                </option>
                                                <option value="follow_up">
                                                    Acompanhamento
                                                </option>
                                                <option value="reactivation">
                                                    Reativação
                                                </option>
                                                <option value="custom">
                                                    Personalizada
                                                </option>
                                            </select>
                                        </Field>
                                        <Field label="Prioridade">
                                            <select
                                                value={step.task_priority}
                                                onChange={(event) =>
                                                    updateStep(
                                                        index,
                                                        'task_priority',
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="low">
                                                    Baixa
                                                </option>
                                                <option value="normal">
                                                    Normal
                                                </option>
                                                <option value="high">
                                                    Alta
                                                </option>
                                                <option value="urgent">
                                                    Urgente
                                                </option>
                                            </select>
                                        </Field>
                                    </div>
                                </article>
                            ))}
                            <InputError message={form.errors.steps} />
                        </section>
                    ) : (
                        <p className="rounded-lg bg-muted p-4 text-sm text-muted-foreground">
                            Este modelo já possui inscrições. As etapas estão
                            bloqueadas para preservar o histórico; crie outro
                            modelo para alterar o fluxo.
                        </p>
                    )}
                    <footer className="flex justify-end gap-2 border-t border-border/70 pt-5">
                        <Button asChild variant="outline">
                            <Link href={journeysIndex.url()}>Cancelar</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="gap-2"
                        >
                            <Save className="size-4" /> Salvar modelo
                        </Button>
                    </footer>
                </form>
            </main>
        </>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

JourneyForm.layout = {
    breadcrumbs: [
        { title: 'Jornadas', href: journeysIndex.url() },
    ] satisfies BreadcrumbItem[],
};
