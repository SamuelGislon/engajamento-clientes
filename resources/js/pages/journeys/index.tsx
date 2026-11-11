import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Plus, Route, Settings2 } from 'lucide-react';
import {
    create,
    edit,
} from '@/actions/App/Http/Controllers/JourneyTemplateController';
import { updateSettings } from '@/actions/App/Http/Controllers/JourneyTemplateController';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

type Template = {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    steps_count: number;
    active_enrollments_count: number;
};
type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Jornadas', href: '/journeys' },
];

export default function JourneysIndex({
    templates,
    autoEnrollDefaultJourney,
    canManage,
}: {
    templates: Page<Template>;
    autoEnrollDefaultJourney: boolean;
    canManage: boolean;
}) {
    return (
        <>
            <Head title="Jornadas comerciais" />
            <main className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="flex items-center gap-2 text-sm font-medium text-primary">
                            <Route className="size-4" /> Automação comercial
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Jornadas
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Configure modelos de etapas e acompanhe as
                            inscrições ativas.
                        </p>
                    </div>
                    {canManage && (
                        <Button asChild>
                            <Link href={create.url()}>
                                <Plus className="mr-2 size-4" /> Nova jornada
                            </Link>
                        </Button>
                    )}
                </header>
                {canManage && (
                    <section className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border/70 bg-card p-5">
                        <div className="flex items-start gap-3">
                            <Settings2 className="mt-0.5 size-5 text-primary" />
                            <div>
                                <h2 className="font-medium">
                                    Inscrição automática de prospectos
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Ao cadastrar um prospecto, iniciar a jornada
                                    padrão e sua primeira tarefa.
                                </p>
                            </div>
                        </div>
                        <select
                            aria-label="Inscrição automática"
                            value={autoEnrollDefaultJourney ? 'on' : 'off'}
                            onChange={(event) =>
                                router.patch(updateSettings.url(), {
                                    auto_enroll_default_journey:
                                        event.target.value === 'on',
                                })
                            }
                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="on">Ligada</option>
                            <option value="off">Desligada</option>
                        </select>
                    </section>
                )}
                <section className="grid gap-4 md:grid-cols-2">
                    {templates.data.map((template) => (
                        <article
                            key={template.id}
                            className="rounded-2xl border border-border/70 bg-card p-5"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-semibold">
                                            {template.name}
                                        </h2>
                                        {template.is_default && (
                                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                                Padrão
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {template.description ||
                                            'Sem descrição.'}
                                    </p>
                                </div>
                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs ${template.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-muted text-muted-foreground'}`}
                                >
                                    {template.is_active ? 'Ativa' : 'Inativa'}
                                </span>
                            </div>
                            <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground">
                                <span>{template.steps_count} etapas</span>
                                <span>
                                    {template.active_enrollments_count}{' '}
                                    inscrições abertas
                                </span>
                            </div>
                            {canManage && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="mt-5"
                                >
                                    <Link href={edit.url(template.id)}>
                                        Editar modelo{' '}
                                        <ArrowRight className="ml-2 size-4" />
                                    </Link>
                                </Button>
                            )}
                        </article>
                    ))}
                </section>
                {templates.links.length > 3 && (
                    <nav
                        aria-label="Paginação de jornadas"
                        className="flex flex-wrap gap-2"
                    >
                        {templates.links.map((link, index) => (
                            <Button
                                key={`${link.label}-${index}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </nav>
                )}
                {templates.data.length === 0 && (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Ainda não há modelos de jornada para esta organização.
                    </p>
                )}
            </main>
        </>
    );
}

JourneysIndex.layout = { breadcrumbs } satisfies {
    breadcrumbs: BreadcrumbItem[];
};
