import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { update as updateSettings } from '@/actions/App/Http/Controllers/CommercialSettingsController';
import { index as salesIndex } from '@/actions/App/Http/Controllers/SaleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Settings = {
    sales_inactivity_days: number;
    relationship_inactivity_days: number;
    reactivation_repeat_cooldown_days: number;
    contact_frequency_days: { A: number; B: number; C: number; D: number };
    post_sale_journey_template_id: number | null;
    reactivation_journey_template_id: number | null;
};
type JourneyTemplate = {
    id: number;
    name: string;
    type: string;
    trigger_type: string;
};

const tierLabels: Record<'A' | 'B' | 'C' | 'D', string> = {
    A: 'Faixa A — estratégica',
    B: 'Faixa B — prioridade alta',
    C: 'Faixa C — acompanhamento regular',
    D: 'Faixa D — acompanhamento espaçado',
};

export default function CommercialSettings({
    settings,
    journeyTemplates,
}: {
    settings: Settings;
    journeyTemplates: JourneyTemplate[];
}) {
    const form = useForm({
        sales_inactivity_days: settings.sales_inactivity_days,
        relationship_inactivity_days: settings.relationship_inactivity_days,
        reactivation_repeat_cooldown_days:
            settings.reactivation_repeat_cooldown_days,
        contact_frequency_days: { ...settings.contact_frequency_days },
        post_sale_journey_template_id:
            settings.post_sale_journey_template_id?.toString() ?? '',
        reactivation_journey_template_id:
            settings.reactivation_journey_template_id?.toString() ?? '',
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(updateSettings.url());
    }

    return (
        <>
            <Head title="Configurações comerciais" />
            <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-5 py-8 md:px-8">
                <Link
                    href={salesIndex.url()}
                    className="inline-flex w-fit items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Voltar para vendas
                </Link>
                <header>
                    <p className="text-sm font-medium text-primary">
                        Regras da organização
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Configurações comerciais
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Ajuste quando a plataforma identifica falta de compra ou
                        contato. A classificação dos clientes usa a faixa para
                        sugerir a frequência de relacionamento.
                    </p>
                </header>

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <section className="grid gap-5 rounded-2xl border border-border/70 bg-card p-5 md:grid-cols-2 md:p-7">
                        <IntervalField
                            label="Alerta de inatividade comercial"
                            help="Dias sem compra confirmada antes de abrir uma ocorrência de reativação. Prospectos sem compras são excluídos."
                            value={form.data.sales_inactivity_days}
                            error={form.errors.sales_inactivity_days}
                            onChange={(value) =>
                                form.setData('sales_inactivity_days', value)
                            }
                        />
                        <IntervalField
                            label="Alerta de inatividade de relacionamento"
                            help="Dias sem interação comercial registrada. Tarefas concluídas não contam como contato."
                            value={form.data.relationship_inactivity_days}
                            error={form.errors.relationship_inactivity_days}
                            onChange={(value) =>
                                form.setData(
                                    'relationship_inactivity_days',
                                    value,
                                )
                            }
                        />
                        <IntervalField
                            label="Intervalo mínimo para nova ação de reativação"
                            help="Evita reabrir uma tarefa repetida para a mesma condição após o vendedor concluir ou cancelar a anterior."
                            value={form.data.reactivation_repeat_cooldown_days}
                            error={
                                form.errors.reactivation_repeat_cooldown_days
                            }
                            onChange={(value) =>
                                form.setData(
                                    'reactivation_repeat_cooldown_days',
                                    value,
                                )
                            }
                        />
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-7">
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Frequência recomendada de contato
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Intervalo em dias por faixa comercial. O
                                acompanhamento usa interações reais registradas
                                na empresa.
                            </p>
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            {(['A', 'B', 'C', 'D'] as const).map((tier) => (
                                <IntervalField
                                    key={tier}
                                    label={tierLabels[tier]}
                                    help="Dias entre contatos recomendados."
                                    value={
                                        form.data.contact_frequency_days[tier]
                                    }
                                    error={
                                        form.errors[
                                            `contact_frequency_days.${tier}`
                                        ]
                                    }
                                    onChange={(value) =>
                                        form.setData('contact_frequency_days', {
                                            ...form.data.contact_frequency_days,
                                            [tier]: value,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    </section>

                    <section className="grid gap-5 rounded-2xl border border-border/70 bg-card p-5 md:grid-cols-2 md:p-7">
                        <JourneyField
                            label="Jornada após a primeira compra"
                            help="Inicia quando um prospecto registra sua primeira venda confirmada."
                            value={form.data.post_sale_journey_template_id}
                            error={form.errors.post_sale_journey_template_id}
                            templates={journeyTemplates.filter(
                                (template) => template.type === 'post_sale',
                            )}
                            onChange={(value) =>
                                form.setData(
                                    'post_sale_journey_template_id',
                                    value,
                                )
                            }
                        />
                        <JourneyField
                            label="Jornada de reativação comercial"
                            help="Inicia quando um cliente ultrapassa o intervalo sem compra confirmada."
                            value={form.data.reactivation_journey_template_id}
                            error={form.errors.reactivation_journey_template_id}
                            templates={journeyTemplates.filter(
                                (template) => template.type === 'reactivation',
                            )}
                            onChange={(value) =>
                                form.setData(
                                    'reactivation_journey_template_id',
                                    value,
                                )
                            }
                        />
                    </section>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            <Save className="size-4" /> Salvar configurações
                        </Button>
                    </div>
                </form>
            </main>
        </>
    );
}

function JourneyField({
    label,
    help,
    value,
    error,
    templates,
    onChange,
}: {
    label: string;
    help: string;
    value: string;
    error?: string;
    templates: JourneyTemplate[];
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid content-start gap-2 text-sm font-medium">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            >
                <option value="">Sem jornada configurada</option>
                {templates.map((template) => (
                    <option key={template.id} value={template.id}>
                        {template.name}
                    </option>
                ))}
            </select>
            <span className="text-xs font-normal text-muted-foreground">
                {help}
            </span>
            <InputError message={error} />
        </label>
    );
}

function IntervalField({
    label,
    help,
    value,
    error,
    onChange,
}: {
    label: string;
    help: string;
    value: number;
    error?: string;
    onChange: (value: number) => void;
}) {
    return (
        <label className="grid content-start gap-2 text-sm font-medium">
            {label}
            <Input
                type="number"
                min={1}
                max={3650}
                value={value}
                onChange={(event) => onChange(Number(event.target.value))}
                required
            />
            <span className="text-xs font-normal text-muted-foreground">
                {help}
            </span>
            <InputError message={error} />
        </label>
    );
}

CommercialSettings.layout = {
    breadcrumbs: [
        { title: 'Configurações comerciais', href: updateSettings.url() },
    ],
} satisfies { breadcrumbs: BreadcrumbItem[] };
