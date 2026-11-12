import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowUpRight, ReceiptText, Search } from 'lucide-react';
import { index as salesIndex } from '@/actions/App/Http/Controllers/SaleController';
import {
    cancel as cancelSale,
    edit as editSale,
} from '@/actions/App/Http/Controllers/SaleController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Sale = {
    id: number;
    description: string;
    amount: string;
    currency: string;
    sold_at: string;
    status: 'confirmed' | 'cancelled';
    can_manage: boolean;
    company: { id: number; name: string };
    seller: { id: number; name: string };
};

type PageLink = { url: string | null; label: string; active: boolean };
type SalePage = {
    data: Sale[];
    links: PageLink[];
    total: number;
    from: number | null;
    to: number | null;
};
type Option = { id: number; name: string };

function dateLabel(value: string, timeZone: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'medium',
        timeZone,
    }).format(new Date(value));
}

function moneyLabel(value: string, currency: string): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency,
    }).format(Number(value));
}

export default function SalesIndex({
    sales,
    filters,
    companies,
    sellers,
    salesTimezone,
}: {
    sales: SalePage;
    filters: {
        status?: string;
        company_id?: number;
        seller_id?: number;
        from?: string;
        to?: string;
    };
    companies: Option[];
    sellers: Option[];
    salesTimezone: string;
}) {
    const form = useForm({
        status: filters.status ?? '',
        company_id: filters.company_id?.toString() ?? '',
        seller_id: filters.seller_id?.toString() ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    function submitFilters(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get(salesIndex.url(), { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Vendas" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="text-sm font-medium text-primary">
                        Organização comercial
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Vendas
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Histórico de compras registradas para as empresas da sua
                        carteira.
                    </p>
                </header>

                <form
                    onSubmit={submitFilters}
                    className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:grid-cols-2 xl:grid-cols-6"
                >
                    <select
                        aria-label="Filtrar por cliente"
                        value={form.data.company_id}
                        onChange={(event) =>
                            form.setData('company_id', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os clientes</option>
                        {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por vendedor"
                        value={form.data.seller_id}
                        onChange={(event) =>
                            form.setData('seller_id', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todos os vendedores</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>
                                {seller.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filtrar por situação"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Todas as situações</option>
                        <option value="confirmed">Confirmadas</option>
                        <option value="cancelled">Canceladas</option>
                    </select>
                    <Input
                        aria-label="Data inicial"
                        type="date"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        aria-label="Data final"
                        type="date"
                        value={form.data.to}
                        onChange={(event) =>
                            form.setData('to', event.target.value)
                        }
                    />
                    <Button type="submit" disabled={form.processing}>
                        <Search className="size-4" /> Filtrar
                    </Button>
                </form>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="flex items-center justify-between border-b border-border/70 px-5 py-4">
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Histórico de vendas
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {sales.from ?? 0}–{sales.to ?? 0} de{' '}
                                {sales.total}
                            </p>
                        </div>
                        <ReceiptText className="size-5 text-muted-foreground" />
                    </div>
                    {sales.data.length === 0 ? (
                        <div className="px-6 py-14 text-center">
                            <p className="font-medium">
                                Nenhuma venda encontrada
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Registre uma compra na página da empresa para
                                acompanhar o histórico comercial.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">
                                            Cliente
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Descrição
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Vendedor
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Data
                                        </th>
                                        <th className="px-5 py-3 text-right font-medium">
                                            Valor
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Situação
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {sales.data.map((sale) => (
                                        <tr key={sale.id}>
                                            <td className="px-5 py-4 font-medium">
                                                <Link
                                                    href={showCompany.url(
                                                        sale.company.id,
                                                    )}
                                                    className="inline-flex items-center gap-1 hover:text-primary"
                                                >
                                                    {sale.company.name}
                                                    <ArrowUpRight className="size-3.5" />
                                                </Link>
                                            </td>
                                            <td className="px-5 py-4">
                                                {sale.description}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {sale.seller.name}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {dateLabel(
                                                    sale.sold_at,
                                                    salesTimezone,
                                                )}
                                            </td>
                                            <td className="px-5 py-4 text-right font-medium tabular-nums">
                                                {moneyLabel(
                                                    sale.amount,
                                                    sale.currency,
                                                )}
                                            </td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${sale.status === 'confirmed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-muted text-muted-foreground'}`}
                                                >
                                                    {sale.status === 'confirmed'
                                                        ? 'Confirmada'
                                                        : 'Cancelada'}
                                                </span>
                                                {sale.can_manage && (
                                                    <span className="ml-2 inline-flex gap-1">
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            <Link
                                                                href={editSale.url(
                                                                    sale.id,
                                                                )}
                                                            >
                                                                Editar
                                                            </Link>
                                                        </Button>
                                                        {sale.status ===
                                                            'confirmed' && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    router.post(
                                                                        cancelSale.url(
                                                                            sale.id,
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                Cancelar
                                                            </Button>
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <nav
                        className="flex flex-wrap justify-center gap-1 border-t border-border/70 px-4 py-3"
                        aria-label="Paginação das vendas"
                    >
                        {sales.links.map((link, index) => {
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

SalesIndex.layout = {
    breadcrumbs: [{ title: 'Vendas', href: salesIndex.url() }],
} satisfies { breadcrumbs: BreadcrumbItem[] };
