import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2, Plus, Search, UsersRound } from 'lucide-react';
import { useState } from 'react';
import {
    create as createCompany,
    index as companiesIndex,
    show as showCompany,
} from '@/actions/App/Http/Controllers/CompanyController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Company = {
    id: number;
    name: string;
    trade_name: string | null;
    segment: string | null;
    city: string | null;
    relationship_status: string;
    owner: { id: number; name: string } | null;
    contacts_count: number;
    activities_max_occurred_at: string | null;
};

type PageLink = { url: string | null; label: string; active: boolean };

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

function dateLabel(value: string | null): string {
    if (!value) return 'Sem interação';
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}

export default function CompaniesIndex({
    companies,
    filters,
}: {
    companies: {
        data: Company[];
        links: PageLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { q?: string; status?: string };
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    return (
        <>
            <Head title="Empresas" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-sm font-medium text-primary">
                            Sua carteira
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            Empresas
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Acompanhe prospectos, clientes e parceiros em um só
                            lugar.
                        </p>
                    </div>
                    <Button
                        asChild
                        className="h-10 gap-2 self-start rounded-lg px-4 sm:self-auto"
                    >
                        <Link href={createCompany.url()}>
                            <Plus className="size-4" /> Nova empresa
                        </Link>
                    </Button>
                </header>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm shadow-black/[0.02]">
                    <form
                        action={companiesIndex.url()}
                        method="get"
                        className="flex flex-col gap-3 border-b border-border/70 p-4 sm:flex-row sm:items-center"
                    >
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                name="q"
                                placeholder="Buscar por empresa ou cidade"
                                className="h-10 rounded-lg pl-9"
                            />
                        </div>
                        <select
                            name="status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="h-10 rounded-lg border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Todas as situações</option>
                            {Object.entries(statusLabels).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <Button
                            type="submit"
                            variant="secondary"
                            className="h-10 rounded-lg px-4"
                        >
                            Filtrar
                        </Button>
                    </form>

                    {companies.data.length === 0 ? (
                        <div className="px-6 py-16 text-center">
                            <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <Building2 className="size-5" />
                            </span>
                            <h2 className="mt-4 font-semibold">
                                Nenhuma empresa encontrada
                            </h2>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                                Experimente outro filtro ou cadastre a primeira
                                empresa da sua carteira.
                            </p>
                            <Button asChild className="mt-5 rounded-lg">
                                <Link href={createCompany.url()}>
                                    Cadastrar empresa
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="bg-muted/35 text-xs tracking-[0.08em] text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-5 py-3.5 font-medium">
                                                Empresa
                                            </th>
                                            <th className="px-5 py-3.5 font-medium">
                                                Situação
                                            </th>
                                            <th className="px-5 py-3.5 font-medium">
                                                Responsável
                                            </th>
                                            <th className="px-5 py-3.5 font-medium">
                                                Profissionais
                                            </th>
                                            <th className="px-5 py-3.5 font-medium">
                                                Último contato
                                            </th>
                                            <th
                                                className="px-5 py-3.5"
                                                aria-label="Abrir empresa"
                                            />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/60">
                                        {companies.data.map((company) => (
                                            <tr
                                                key={company.id}
                                                className="group transition-colors hover:bg-muted/25"
                                            >
                                                <td className="px-5 py-4">
                                                    <Link
                                                        href={showCompany.url(
                                                            company.id,
                                                        )}
                                                        className="block min-w-0"
                                                    >
                                                        <span className="block truncate font-medium group-hover:text-primary">
                                                            {company.trade_name ||
                                                                company.name}
                                                        </span>
                                                        <span className="mt-1 block text-xs text-muted-foreground">
                                                            {[
                                                                company.segment,
                                                                company.city,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ') ||
                                                                'Segmento não informado'}
                                                        </span>
                                                    </Link>
                                                </td>
                                                <td className="px-5 py-4">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusStyles[company.relationship_status] ?? 'bg-muted text-muted-foreground'}`}
                                                    >
                                                        {statusLabels[
                                                            company
                                                                .relationship_status
                                                        ] ??
                                                            company.relationship_status}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-4 text-muted-foreground">
                                                    {company.owner?.name ??
                                                        'Sem responsável'}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                                        <UsersRound className="size-4" />
                                                        {company.contacts_count}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-4 text-muted-foreground">
                                                    {dateLabel(
                                                        company.activities_max_occurred_at,
                                                    )}
                                                </td>
                                                <td className="px-5 py-4 text-right">
                                                    <Link
                                                        aria-label={`Abrir ${company.name}`}
                                                        href={showCompany.url(
                                                            company.id,
                                                        )}
                                                        className="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                    >
                                                        <ArrowRight className="size-4" />
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="flex flex-col gap-3 border-t border-border/70 px-5 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    {companies.from ?? 0}–{companies.to ?? 0} de{' '}
                                    {companies.total} empresas
                                </span>
                                <nav
                                    aria-label="Paginação de empresas"
                                    className="flex flex-wrap gap-1"
                                >
                                    {companies.links.map((link, index) =>
                                        link.url ? (
                                            <Link
                                                key={`${link.label}-${index}`}
                                                href={link.url}
                                                className={`rounded-md px-3 py-1.5 text-xs ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                            >
                                                <span
                                                    dangerouslySetInnerHTML={{
                                                        __html: link.label,
                                                    }}
                                                />
                                            </Link>
                                        ) : (
                                            <span
                                                key={`${link.label}-${index}`}
                                                className="cursor-not-allowed rounded-md px-3 py-1.5 text-xs opacity-40"
                                            >
                                                <span
                                                    dangerouslySetInnerHTML={{
                                                        __html: link.label,
                                                    }}
                                                />
                                            </span>
                                        ),
                                    )}
                                </nav>
                            </div>
                        </>
                    )}
                </section>
            </main>
        </>
    );
}

CompaniesIndex.layout = {
    breadcrumbs: [{ title: 'Empresas', href: companiesIndex.url() }],
};
