import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Check,
    FileUp,
    UsersRound,
} from 'lucide-react';
import {
    advance,
    complete,
    updateJourneys,
    updateOrganization,
    updateRelationship,
} from '@/actions/App/Http/Controllers/OnboardingController';
import { index as usersIndex } from '@/actions/App/Http/Controllers/OrganizationInvitationController';
import { create as createCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { index as importsIndex } from '@/actions/App/Http/Controllers/DataImportController';
import { index as journeysIndex } from '@/actions/App/Http/Controllers/JourneyTemplateController';
import { index as teamsIndex } from '@/actions/App/Http/Controllers/TeamController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Step =
    | 'organization'
    | 'relationship'
    | 'journeys'
    | 'invite'
    | 'portfolio';
type Organization = {
    id: number;
    name: string;
    timezone: string;
    auto_enroll_default_journey: boolean;
};
type Journey = { id: number; name: string; is_default: boolean };

const steps: { id: Step; title: string; detail: string }[] = [
    { id: 'organization', title: 'Organização', detail: 'Dados básicos' },
    { id: 'relationship', title: 'Relacionamento', detail: 'Regras iniciais' },
    { id: 'journeys', title: 'Jornadas', detail: 'Automação' },
    { id: 'invite', title: 'Colaboradores', detail: 'Opcional' },
    { id: 'portfolio', title: 'Carteira', detail: 'Opcional' },
];

export default function Onboarding({
    step,
    organization,
    commercialSettings,
    journeys,
    timezones,
}: {
    step: Step;
    organization: Organization;
    commercialSettings: {
        sales_inactivity_days: number;
        relationship_inactivity_days: number;
    };
    journeys: Journey[];
    timezones: string[];
}) {
    const organizationForm = useForm({
        name: organization.name,
        timezone: organization.timezone,
    });
    const relationshipForm = useForm({
        sales_inactivity_days: commercialSettings.sales_inactivity_days,
        relationship_inactivity_days:
            commercialSettings.relationship_inactivity_days,
    });
    const journeyForm = useForm({
        default_journey_template_id:
            journeys.find((journey) => journey.is_default)?.id.toString() ??
            journeys[0]?.id.toString() ??
            '',
        auto_enroll_default_journey: organization.auto_enroll_default_journey,
    });
    const currentStepIndex = steps.findIndex((item) => item.id === step);
    const timezoneOptions = Array.from(
        new Set([organization.timezone, ...timezones]),
    );

    return (
        <>
            <Head title="Configuração inicial" />
            <main className="mx-auto flex w-full max-w-4xl flex-col gap-7 px-5 py-10 md:px-8">
                <header>
                    <p className="text-sm font-medium text-primary">
                        Primeiros passos
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Configure seu espaço comercial
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Salve cada etapa e continue quando quiser. Convites e
                        cadastro da carteira podem ser feitos agora ou depois.
                    </p>
                </header>

                <ol
                    className="grid gap-2 sm:grid-cols-5"
                    aria-label="Etapas do onboarding"
                >
                    {steps.map((item, index) => {
                        const isComplete = index < currentStepIndex;
                        const isCurrent = index === currentStepIndex;

                        return (
                            <li
                                key={item.id}
                                aria-current={isCurrent ? 'step' : undefined}
                                className={`rounded-xl border p-3 ${
                                    isCurrent
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border/70 bg-card'
                                }`}
                            >
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <span className="flex size-6 items-center justify-center rounded-full bg-muted text-xs">
                                        {isComplete ? (
                                            <Check className="size-3.5" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>
                                    {item.title}
                                </div>
                                <p className="mt-1 pl-8 text-xs text-muted-foreground">
                                    {item.detail}
                                </p>
                            </li>
                        );
                    })}
                </ol>

                {step === 'organization' && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:p-7">
                        <div className="mb-6 flex items-start gap-3">
                            <Building2 className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="text-xl font-semibold">
                                    Dados da organização
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Defina como o espaço aparece e o fuso usado
                                    para datas comerciais.
                                </p>
                            </div>
                        </div>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                organizationForm.patch(
                                    updateOrganization.url(),
                                );
                            }}
                            className="grid gap-5"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    Nome da organização
                                </Label>
                                <Input
                                    id="name"
                                    value={organizationForm.data.name}
                                    onChange={(event) =>
                                        organizationForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={120}
                                />
                                <InputError
                                    message={organizationForm.errors.name}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Fuso horário</Label>
                                <select
                                    id="timezone"
                                    value={organizationForm.data.timezone}
                                    onChange={(event) =>
                                        organizationForm.setData(
                                            'timezone',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {timezoneOptions.map((timezone) => (
                                        <option key={timezone} value={timezone}>
                                            {timezone}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={organizationForm.errors.timezone}
                                />
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={organizationForm.processing}
                                >
                                    Salvar e continuar <ArrowRight />
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                {step === 'relationship' && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:p-7">
                        <h2 className="text-xl font-semibold">
                            Configuração inicial do relacionamento
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Esses prazos orientam os alertas de inatividade e
                            podem ser refinados nas configurações comerciais.
                        </p>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                relationshipForm.patch(
                                    updateRelationship.url(),
                                );
                            }}
                            className="mt-6 grid gap-5 md:grid-cols-2"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="sales_inactivity_days">
                                    Dias sem compra para alerta
                                </Label>
                                <Input
                                    id="sales_inactivity_days"
                                    type="number"
                                    min={7}
                                    max={3650}
                                    value={
                                        relationshipForm.data
                                            .sales_inactivity_days
                                    }
                                    onChange={(event) =>
                                        relationshipForm.setData(
                                            'sales_inactivity_days',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        relationshipForm.errors
                                            .sales_inactivity_days
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="relationship_inactivity_days">
                                    Dias sem contato para alerta
                                </Label>
                                <Input
                                    id="relationship_inactivity_days"
                                    type="number"
                                    min={1}
                                    max={365}
                                    value={
                                        relationshipForm.data
                                            .relationship_inactivity_days
                                    }
                                    onChange={(event) =>
                                        relationshipForm.setData(
                                            'relationship_inactivity_days',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        relationshipForm.errors
                                            .relationship_inactivity_days
                                    }
                                />
                            </div>
                            <div className="flex justify-end md:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={relationshipForm.processing}
                                >
                                    Salvar e continuar <ArrowRight />
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                {step === 'journeys' && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:p-7">
                        <div className="mb-6">
                            <h2 className="text-xl font-semibold">
                                Escolha a jornada de prospecção padrão
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Você pode iniciar essa jornada automaticamente
                                ao cadastrar um novo prospecto.
                            </p>
                        </div>
                        {journeys.length > 0 ? (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    journeyForm.patch(updateJourneys.url());
                                }}
                                className="grid gap-5"
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="default_journey_template_id">
                                        Jornada padrão
                                    </Label>
                                    <select
                                        id="default_journey_template_id"
                                        value={
                                            journeyForm.data
                                                .default_journey_template_id
                                        }
                                        onChange={(event) =>
                                            journeyForm.setData(
                                                'default_journey_template_id',
                                                event.target.value,
                                            )
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        {journeys.map((journey) => (
                                            <option
                                                key={journey.id}
                                                value={journey.id}
                                            >
                                                {journey.name}
                                                {journey.is_default
                                                    ? ' (atual)'
                                                    : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            journeyForm.errors
                                                .default_journey_template_id
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="auto_enroll_default_journey">
                                        Inscrição automática de prospectos
                                    </Label>
                                    <select
                                        id="auto_enroll_default_journey"
                                        value={
                                            journeyForm.data
                                                .auto_enroll_default_journey
                                                ? 'on'
                                                : 'off'
                                        }
                                        onChange={(event) =>
                                            journeyForm.setData(
                                                'auto_enroll_default_journey',
                                                event.target.value === 'on',
                                            )
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="on">Ligada</option>
                                        <option value="off">Desligada</option>
                                    </select>
                                    <InputError
                                        message={
                                            journeyForm.errors
                                                .auto_enroll_default_journey
                                        }
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <Button asChild variant="outline">
                                        <Link
                                            href={journeysIndex.url()}
                                            target="_blank"
                                        >
                                            Ver modelos <ArrowLeft />
                                        </Link>
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={journeyForm.processing}
                                    >
                                        Salvar e continuar <ArrowRight />
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <div className="grid gap-4">
                                <p className="text-sm text-muted-foreground">
                                    Crie um modelo de prospecção para selecionar
                                    como padrão.
                                </p>
                                <Button asChild className="w-fit">
                                    <Link
                                        href={journeysIndex.url()}
                                        target="_blank"
                                    >
                                        Abrir jornadas
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </section>
                )}

                {step === 'invite' && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:p-7">
                        <div className="mb-6 flex items-start gap-3">
                            <UsersRound className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="text-xl font-semibold">
                                    Convide colaboradores
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Convites são opcionais. Você pode configurar
                                    equipes e funções na gestão de usuários.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap justify-between gap-3">
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link
                                        href={usersIndex.url()}
                                        target="_blank"
                                    >
                                        <UsersRound /> Convidar colaboradores
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link
                                        href={teamsIndex.url()}
                                        target="_blank"
                                    >
                                        Configurar equipes
                                    </Link>
                                </Button>
                            </div>
                            <Button onClick={() => router.post(advance.url())}>
                                Continuar sem convidar <ArrowRight />
                            </Button>
                        </div>
                    </section>
                )}

                {step === 'portfolio' && (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:p-7">
                        <div className="mb-6 flex items-start gap-3">
                            <FileUp className="mt-1 size-5 text-primary" />
                            <div>
                                <h2 className="text-xl font-semibold">
                                    Adicione sua primeira carteira
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Cadastre uma empresa ou importe uma planilha
                                    existente. Essa etapa também pode ficar para
                                    depois.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap justify-between gap-3">
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link
                                        href={createCompany.url()}
                                        target="_blank"
                                    >
                                        <Building2 /> Cadastrar empresa
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link
                                        href={importsIndex.url()}
                                        target="_blank"
                                    >
                                        <FileUp /> Importar carteira
                                    </Link>
                                </Button>
                            </div>
                            <Button onClick={() => router.post(complete.url())}>
                                Concluir e abrir o dashboard <Check />
                            </Button>
                        </div>
                    </section>
                )}

                <p className="text-center text-xs text-muted-foreground">
                    Seu progresso é salvo na organização e você pode retomar
                    esta configuração ao abrir o dashboard.
                </p>
            </main>
        </>
    );
}
