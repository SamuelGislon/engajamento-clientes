import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, RotateCcw, Save, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    resumeScoreRecalculation,
    update as updateSettings,
} from '@/actions/App/Http/Controllers/CommercialSettingsController';
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
    scoring_weights: Record<
        'volume' | 'frequency' | 'potential' | 'relationship',
        number
    >;
    volume_score_bands: { max_amount: string | null; points: number }[];
    frequency_score_bands: { min_purchases: number; points: number }[];
    tiers: {
        key: 'A' | 'B' | 'C' | 'D';
        name: string;
        min_score: number;
        max_score: number;
        color: string;
    }[];
    post_sale_journey_template_id: number | null;
    reactivation_journey_template_id: number | null;
};
type JourneyTemplate = {
    id: number;
    name: string;
    type: string;
    trigger_type: string;
};
type ScoreRecalculation = {
    id: number;
    status: 'queued' | 'processing' | 'completed' | 'failed' | 'superseded';
    total_companies?: number;
    processed_companies?: number;
    last_error: string | null;
    updated_at: string;
};

const weightLabels: Record<
    'volume' | 'frequency' | 'potential' | 'relationship',
    string
> = {
    volume: 'Faturamento',
    frequency: 'Frequência de compras',
    potential: 'Potencial comercial',
    relationship: 'Facilidade de relacionamento',
};

export default function CommercialSettings({
    settings,
    scoreRecalculation,
    journeyTemplates,
}: {
    settings: Settings;
    scoreRecalculation: ScoreRecalculation | null;
    journeyTemplates: JourneyTemplate[];
}) {
    const form = useForm({
        sales_inactivity_days: settings.sales_inactivity_days,
        relationship_inactivity_days: settings.relationship_inactivity_days,
        reactivation_repeat_cooldown_days:
            settings.reactivation_repeat_cooldown_days,
        contact_frequency_days: { ...settings.contact_frequency_days },
        scoring_weights: { ...settings.scoring_weights },
        volume_score_bands: settings.volume_score_bands.map((band) => ({
            ...band,
        })),
        frequency_score_bands: settings.frequency_score_bands.map((band) => ({
            ...band,
        })),
        tiers: settings.tiers.map((tier) => ({ ...tier })),
        post_sale_journey_template_id:
            settings.post_sale_journey_template_id?.toString() ?? '',
        reactivation_journey_template_id:
            settings.reactivation_journey_template_id?.toString() ?? '',
    });
    const resumeForm = useForm({});
    const [confirmWeightReduction, setConfirmWeightReduction] = useState(false);
    const formErrors = form.errors as Record<string, string | undefined>;
    const reducesManualWeight =
        form.data.scoring_weights.potential <
            settings.scoring_weights.potential ||
        form.data.scoring_weights.relationship <
            settings.scoring_weights.relationship;
    const canResumeRecalculation =
        scoreRecalculation?.status === 'failed' ||
        (scoreRecalculation !== null &&
            scoreRecalculation.status !== 'completed' &&
            scoreRecalculation.status !== 'superseded' &&
            Date.now() - new Date(scoreRecalculation.updated_at).getTime() >=
                10 * 60 * 1000);

    useEffect(() => {
        if (
            scoreRecalculation === null ||
            scoreRecalculation.status === 'completed' ||
            scoreRecalculation.status === 'failed' ||
            scoreRecalculation.status === 'superseded'
        ) {
            return;
        }

        const intervalId = window.setInterval(() => {
            router.reload({ only: ['scoreRecalculation'] });
        }, 5000);

        return () => window.clearInterval(intervalId);
    }, [scoreRecalculation?.status]);
    const scoringWeightTotal = Object.values(form.data.scoring_weights).reduce(
        (sum, weight) => sum + Number(weight || 0),
        0,
    );

    function addVolumeBand() {
        const bands = [...form.data.volume_score_bands];
        const lastBand = bands.pop();
        if (!lastBand) return;
        const previousPoints = bands.at(-1)?.points ?? 0;
        const maximumPoints = form.data.scoring_weights.volume;
        form.setData('volume_score_bands', [
            ...bands,
            {
                max_amount: '',
                points: Math.max(previousPoints, maximumPoints - 1),
            },
            { max_amount: null, points: maximumPoints },
        ]);
    }

    function addFrequencyBand() {
        const bands = [...form.data.frequency_score_bands];
        const lastBand = bands.at(-1);
        if (!lastBand) return;
        form.setData('frequency_score_bands', [
            ...bands,
            {
                min_purchases: lastBand.min_purchases + 1,
                points: form.data.scoring_weights.frequency,
            },
        ]);
    }

    function updateScoringWeight(
        key: keyof typeof form.data.scoring_weights,
        value: number,
    ) {
        form.setData('scoring_weights', {
            ...form.data.scoring_weights,
            [key]: value,
        });

        if (key === 'volume') {
            form.setData(
                'volume_score_bands',
                form.data.volume_score_bands.map((band, index, bands) =>
                    index === bands.length - 1
                        ? { ...band, points: value }
                        : band,
                ),
            );
        }

        if (key === 'frequency') {
            form.setData(
                'frequency_score_bands',
                form.data.frequency_score_bands.map((band, index, bands) =>
                    index === bands.length - 1
                        ? { ...band, points: value }
                        : band,
                ),
            );
        }
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (reducesManualWeight) {
            setConfirmWeightReduction(true);

            return;
        }

        saveSettings();
    }

    function saveSettings() {
        setConfirmWeightReduction(false);
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

                {scoreRecalculation && (
                    <section className="grid gap-2 rounded-2xl border border-border/70 bg-card p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">
                                    Recalculo da classificação
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {scoreRecalculation.status === 'completed'
                                        ? 'Concluído'
                                        : scoreRecalculation.status === 'failed'
                                          ? 'Interrompido; é possível retomar'
                                          : scoreRecalculation.status ===
                                              'superseded'
                                            ? 'Substituído por regras mais recentes'
                                            : scoreRecalculation.status ===
                                                'processing'
                                              ? 'Em processamento'
                                              : 'Aguardando processamento'}
                                    {scoreRecalculation.processed_companies !==
                                        undefined &&
                                        scoreRecalculation.total_companies !==
                                            undefined && (
                                            <>
                                                {' · '}
                                                {scoreRecalculation.processed_companies.toLocaleString(
                                                    'pt-BR',
                                                )}{' '}
                                                de{' '}
                                                {scoreRecalculation.total_companies.toLocaleString(
                                                    'pt-BR',
                                                )}{' '}
                                                empresas
                                            </>
                                        )}
                                </p>
                            </div>
                            {canResumeRecalculation && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={resumeForm.processing}
                                    onClick={() =>
                                        resumeForm.post(
                                            resumeScoreRecalculation.url(
                                                scoreRecalculation.id,
                                            ),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RotateCcw className="size-4" /> Retomar
                                </Button>
                            )}
                        </div>
                        {scoreRecalculation.last_error && (
                            <p className="text-sm text-destructive">
                                {scoreRecalculation.last_error}
                            </p>
                        )}
                    </section>
                )}

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
                                Pesos da classificação comercial
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Cada peso define a contribuição máxima do
                                critério. A soma precisa ser 100 pontos.
                            </p>
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            {(
                                Object.keys(
                                    weightLabels,
                                ) as (keyof typeof weightLabels)[]
                            ).map((key) => (
                                <label
                                    key={key}
                                    className="grid gap-2 text-sm font-medium"
                                >
                                    {weightLabels[key]} · até 100
                                    <Input
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={form.data.scoring_weights[key]}
                                        onChange={(event) =>
                                            updateScoringWeight(
                                                key,
                                                Number(event.target.value),
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={
                                            form.errors[
                                                `scoring_weights.${key}`
                                            ]
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                        <p
                            className={`mt-4 text-sm ${scoringWeightTotal === 100 ? 'text-muted-foreground' : 'text-destructive'}`}
                        >
                            Soma atual: {scoringWeightTotal}/100
                        </p>
                        <InputError message={form.errors.scoring_weights} />
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
                                    label={`Faixa ${tier} — ${form.data.tiers.find((item) => item.key === tier)?.name ?? ''}`}
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

                    <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-7">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold tracking-tight">
                                    Faixas de faturamento
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Os valores são limites acumulados em 12
                                    meses. A última faixa não tem limite e
                                    alcança o peso configurado.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addVolumeBand}
                            >
                                <Plus className="size-4" /> Adicionar faixa
                            </Button>
                        </div>
                        <div className="mt-5 grid gap-3">
                            {form.data.volume_score_bands.map((band, index) => (
                                <div
                                    key={`volume-${index}`}
                                    className="grid gap-3 rounded-xl border border-border/70 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                                >
                                    <label className="grid gap-2 text-sm font-medium">
                                        {index ===
                                        form.data.volume_score_bands.length - 1
                                            ? 'Limite de faturamento'
                                            : 'Faturamento máximo (R$)'}
                                        <Input
                                            type={
                                                band.max_amount === null
                                                    ? 'text'
                                                    : 'number'
                                            }
                                            min={0}
                                            max={999999999999.99}
                                            step="0.01"
                                            disabled={band.max_amount === null}
                                            value={
                                                band.max_amount === null
                                                    ? 'Sem limite'
                                                    : band.max_amount
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'volume_score_bands',
                                                    form.data.volume_score_bands.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      max_amount:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `volume_score_bands.${index}.max_amount`
                                                ]
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm font-medium">
                                        Pontos
                                        <Input
                                            type="number"
                                            min={0}
                                            max={
                                                form.data.scoring_weights.volume
                                            }
                                            disabled={
                                                index ===
                                                form.data.volume_score_bands
                                                    .length -
                                                    1
                                            }
                                            value={band.points}
                                            onChange={(event) =>
                                                form.setData(
                                                    'volume_score_bands',
                                                    form.data.volume_score_bands.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      points: Number(
                                                                          event
                                                                              .target
                                                                              .value,
                                                                      ),
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `volume_score_bands.${index}.points`
                                                ]
                                            }
                                        />
                                    </label>
                                    {index <
                                        form.data.volume_score_bands.length -
                                            1 &&
                                    form.data.volume_score_bands.length > 2 ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remover faixa de faturamento"
                                            onClick={() =>
                                                form.setData(
                                                    'volume_score_bands',
                                                    form.data.volume_score_bands.filter(
                                                        (_, itemIndex) =>
                                                            itemIndex !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                </div>
                            ))}
                        </div>
                        <InputError message={form.errors.volume_score_bands} />
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-7">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold tracking-tight">
                                    Faixas de frequência de compras
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    O número de compras considera vendas
                                    confirmadas nos últimos 12 meses.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addFrequencyBand}
                            >
                                <Plus className="size-4" /> Adicionar faixa
                            </Button>
                        </div>
                        <div className="mt-5 grid gap-3">
                            {form.data.frequency_score_bands.map(
                                (band, index) => (
                                    <div
                                        key={`frequency-${index}`}
                                        className="grid gap-3 rounded-xl border border-border/70 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                                    >
                                        <label className="grid gap-2 text-sm font-medium">
                                            Compras mínimas
                                            <Input
                                                type="number"
                                                min={0}
                                                max={1000000}
                                                value={band.min_purchases}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'frequency_score_bands',
                                                        form.data.frequency_score_bands.map(
                                                            (
                                                                item,
                                                                itemIndex,
                                                            ) =>
                                                                itemIndex ===
                                                                index
                                                                    ? {
                                                                          ...item,
                                                                          min_purchases:
                                                                              Number(
                                                                                  event
                                                                                      .target
                                                                                      .value,
                                                                              ),
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    formErrors[
                                                        `frequency_score_bands.${index}.min_purchases`
                                                    ]
                                                }
                                            />
                                        </label>
                                        <label className="grid gap-2 text-sm font-medium">
                                            Pontos
                                            <Input
                                                type="number"
                                                min={0}
                                                max={
                                                    form.data.scoring_weights
                                                        .frequency
                                                }
                                                disabled={
                                                    index ===
                                                    form.data
                                                        .frequency_score_bands
                                                        .length -
                                                        1
                                                }
                                                value={band.points}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'frequency_score_bands',
                                                        form.data.frequency_score_bands.map(
                                                            (
                                                                item,
                                                                itemIndex,
                                                            ) =>
                                                                itemIndex ===
                                                                index
                                                                    ? {
                                                                          ...item,
                                                                          points: Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    formErrors[
                                                        `frequency_score_bands.${index}.points`
                                                    ]
                                                }
                                            />
                                        </label>
                                        {index > 0 &&
                                        form.data.frequency_score_bands.length >
                                            2 ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Remover faixa de frequência"
                                                onClick={() =>
                                                    form.setData(
                                                        'frequency_score_bands',
                                                        form.data.frequency_score_bands.filter(
                                                            (_, itemIndex) =>
                                                                itemIndex !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        ) : (
                                            <span />
                                        )}
                                    </div>
                                ),
                            )}
                        </div>
                        <InputError
                            message={form.errors.frequency_score_bands}
                        />
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 md:p-7">
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Nomes, cores e limites das faixas
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                As faixas precisam cobrir todas as pontuações de
                                0 a 100 sem lacunas ou sobreposições.
                            </p>
                        </div>
                        <div className="mt-5 grid gap-3">
                            {form.data.tiers.map((tier, index) => (
                                <div
                                    key={tier.key}
                                    className="grid gap-3 rounded-xl border border-border/70 p-3 sm:grid-cols-[auto_1fr_1fr_1fr] sm:items-end"
                                >
                                    <span className="pb-2 text-sm font-semibold">
                                        Faixa {tier.key}
                                    </span>
                                    <label className="grid gap-2 text-sm font-medium">
                                        Nome
                                        <Input
                                            maxLength={60}
                                            value={tier.name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tiers',
                                                    form.data.tiers.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      name: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `tiers.${index}.name`
                                                ]
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm font-medium">
                                        Pontuação mínima
                                        <Input
                                            type="number"
                                            min={0}
                                            max={100}
                                            value={tier.min_score}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tiers',
                                                    form.data.tiers.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      min_score:
                                                                          Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `tiers.${index}.min_score`
                                                ]
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm font-medium">
                                        Pontuação máxima
                                        <Input
                                            type="number"
                                            min={0}
                                            max={100}
                                            value={tier.max_score}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tiers',
                                                    form.data.tiers.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      max_score:
                                                                          Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `tiers.${index}.max_score`
                                                ]
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-2 text-sm font-medium sm:col-start-2">
                                        Cor
                                        <Input
                                            type="color"
                                            value={tier.color}
                                            onChange={(event) =>
                                                form.setData(
                                                    'tiers',
                                                    form.data.tiers.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      color: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors[
                                                    `tiers.${index}.color`
                                                ]
                                            }
                                        />
                                    </label>
                                </div>
                            ))}
                        </div>
                        <InputError message={form.errors.tiers} />
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

                    {confirmWeightReduction && (
                        <div
                            role="alert"
                            className="grid gap-3 rounded-xl border border-amber-500/40 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"
                        >
                            <p>
                                Reduzir o peso de potencial ou relacionamento
                                limita avaliações manuais já salvas ao novo
                                máximo durante o recálculo. O histórico anterior
                                será preservado. Deseja continuar?
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setConfirmWeightReduction(false)
                                    }
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    type="button"
                                    disabled={form.processing}
                                    onClick={saveSettings}
                                >
                                    Continuar e salvar
                                </Button>
                            </div>
                        </div>
                    )}

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
