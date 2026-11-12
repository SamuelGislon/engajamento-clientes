import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, Search, Sparkles } from 'lucide-react';
import { index as classificationIndex } from '@/actions/App/Http/Controllers/CustomerScoreController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Score = {
    volume_points: number;
    frequency_points: number;
    potential_points: number | null;
    relationship_points: number | null;
    total_score: number | null;
    tier: string | null;
    is_complete: boolean;
};
type Company = {
    id: number;
    name: string;
    segment: string | null;
    relationship_status: string;
    owner: { id: number; name: string } | null;
    customer_score: Score | null;
    volume_12_months: string;
    purchase_count_12_months: number;
    last_purchase_at: string | null;
    activities_max_occurred_at: string | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type CompanyPage = {
    data: Company[];
    links: PageLink[];
    total: number;
    from: number | null;
    to: number | null;
};
type Option = { id: number; name: string };

const statusLabels: Record<string, string> = {
    prospect: 'Prospecto',
    customer: 'Cliente',
    inactive: 'Inativo',
    partner: 'Parceiro',
};

function dateLabel(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : 'Sem registro';
}

function money(value: string): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value));
}

export default function CommercialClassification({
    companies,
    filters,
    owners,
    segments,
}: {
    companies: CompanyPage;
    filters: {
        tier?: string;
        owner_user_id?: number;
        segment?: string;
        relationship_status?: string;
        from?: string;
        to?: string;
    };
    owners: Option[];
    segments: string[];
}) {
    const form = useForm({
        tier: filters.tier ?? '',
        owner_user_id: filters.owner_user_id?.toString() ?? '',
        segment: filters.segment ?? '',
        relationship_status: filters.relationship_status ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    function submitFilters(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get(classificationIndex.url(), {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <>
            <Head title="Classificação comercial" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="text-sm font-medium text-primary">
                        Inteligência comercial
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Classificação de clientes
                    </h1>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        A pontuação combina compras dos últimos 12 meses,
                        frequência e dois critérios preenchidos pela equipe.
                        Empresas com critérios manuais pendentes aparecem sem
                        faixa final.
                    </p>
                </header>

                <form
                    onSubmit={submitFilters}
                    className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:grid-cols-2 xl:grid-cols-6"
                >
                    <select
                        aria-label="Filtrar por faixa"
                        value={form.data.tier}
                        onChange={(event) =>
                            form.setData('tier', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as faixas</option>
                        <option value="A">Faixa A</option>
                        <option value="B">Faixa B</option>
                        <option value="C">Faixa C</option>
                        <option value="D">Faixa D</option>
                        <option value="incomplete">Dados incompletos</option>
                    </select>
                    <select
                        aria-label="Filtrar por responsável"
                        value={form.data.owner_user_id}
                        onChange={(event) =>
                            form.setData('owner_user_id', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os responsáveis</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>
                                {owner.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por segmento"
                        value={form.data.segment}
                        onChange={(event) =>
                            form.setData('segment', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os segmentos</option>
                        {segments.map((segment) => (
                            <option key={segment} value={segment}>
                                {segment}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por situação comercial"
                        value={form.data.relationship_status}
                        onChange={(event) =>
                            form.setData(
                                'relationship_status',
                                event.target.value,
                            )
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as situações</option>
                        {Object.entries(statusLabels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Compra a partir de"
                        type="date"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        aria-label="Compra até"
                        type="date"
                        value={form.data.to}
                        onChange={(event) =>
                            form.setData('to', event.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="sm:col-span-2 xl:col-span-6 xl:justify-self-end"
                    >
                        <Search className="size-4" /> Filtrar classificação
                    </Button>
                </form>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="flex items-center justify-between border-b border-border/70 px-5 py-4">
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Clientes e composição da pontuação
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {companies.from ?? 0}–{companies.to ?? 0} de{' '}
                                {companies.total}
                            </p>
                        </div>
                        <Sparkles className="size-5 text-muted-foreground" />
                    </div>
                    {companies.data.length === 0 ? (
                        <p className="px-6 py-14 text-center text-sm text-muted-foreground">
                            Nenhuma empresa encontrada para estes filtros.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1100px] text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">
                                            Empresa
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Responsável
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Faixa
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Nota
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Volume 12 meses
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Compras
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Última compra
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Último contato
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {companies.data.map((company) => (
                                        <tr key={company.id}>
                                            <td className="px-5 py-4">
                                                <Link
                                                    href={showCompany.url(
                                                        company.id,
                                                    )}
                                                    className="inline-flex items-center gap-1 font-medium hover:text-primary"
                                                >
                                                    {company.name}
                                                    <ArrowUpRight className="size-3.5" />
                                                </Link>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {company.segment ??
                                                        'Segmento não informado'}
                                                    {' · '}
                                                    {
                                                        statusLabels[
                                                            company
                                                                .relationship_status
                                                        ]
                                                    }
                                                </p>
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {company.owner?.name ??
                                                    'Sem responsável'}
                                            </td>
                                            <td className="px-5 py-4">
                                                <TierBadge
                                                    tier={
                                                        company.customer_score
                                                            ?.tier ?? null
                                                    }
                                                    complete={
                                                        company.customer_score
                                                            ?.is_complete ??
                                                        false
                                                    }
                                                />
                                            </td>
                                            <td className="px-5 py-4 font-semibold tabular-nums">
                                                {company.customer_score
                                                    ?.total_score ?? '—'}
                                            </td>
                                            <td className="px-5 py-4 tabular-nums">
                                                {money(
                                                    company.volume_12_months,
                                                )}
                                            </td>
                                            <td className="px-5 py-4 tabular-nums">
                                                {
                                                    company.purchase_count_12_months
                                                }
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {dateLabel(
                                                    company.last_purchase_at,
                                                )}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {dateLabel(
                                                    company.activities_max_occurred_at,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="grid gap-3 border-t border-border/70 p-4 md:grid-cols-2 xl:grid-cols-3">
                        {companies.data.map((company) => (
                            <details
                                key={`score-${company.id}`}
                                className="rounded-xl border border-border/70 bg-background px-4 py-3"
                            >
                                <summary className="cursor-pointer text-sm font-medium">
                                    Como calculamos {company.name}
                                </summary>
                                <ScoreBreakdown
                                    score={company.customer_score}
                                />
                            </details>
                        ))}
                    </div>
                    <nav
                        className="flex flex-wrap justify-center gap-1 border-t border-border/70 px-4 py-3"
                        aria-label="Paginação da classificação"
                    >
                        {companies.links.map((link, index) => {
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

function TierBadge({
    tier,
    complete,
}: {
    tier: string | null;
    complete: boolean;
}) {
    if (!complete || !tier) {
        return (
            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                Dados incompletos
            </span>
        );
    }

    return (
        <span className="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
            Faixa {tier}
        </span>
    );
}

function ScoreBreakdown({ score }: { score: Score | null }) {
    return (
        <div className="mt-3 grid gap-2 text-xs text-muted-foreground">
            <p>Volume em compras: {score?.volume_points ?? 0}/40 pontos</p>
            <p>
                Frequência em 12 meses: {score?.frequency_points ?? 0}/20 pontos
            </p>
            <p>
                Potencial comercial: {score?.potential_points ?? 'Pendente'}/20
                pontos
            </p>
            <p>
                Acesso e relacionamento:{' '}
                {score?.relationship_points ?? 'Pendente'}/20 pontos
            </p>
            <p className="pt-1 font-medium text-foreground">
                {score?.is_complete
                    ? `Total ${score.total_score}/100 pontos. A faixa usa os intervalos configurados pela organização.`
                    : 'A faixa só é definida depois de preencher os dois critérios manuais. Ausência de informação não conta como zero.'}
            </p>
        </div>
    );
}

CommercialClassification.layout = {
    breadcrumbs: [
        { title: 'Classificação comercial', href: classificationIndex.url() },
    ],
} satisfies { breadcrumbs: BreadcrumbItem[] };
