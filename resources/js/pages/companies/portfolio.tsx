import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRightLeft, Building2 } from 'lucide-react';
import {
    index as portfolioIndex,
    transfer as transferPortfolio,
} from '@/actions/App/Http/Controllers/CompanyPortfolioController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Seller = { id: number; name: string; team_ids: number[] };
type Team = { id: number; name: string };
type PortfolioCompany = {
    id: number;
    name: string;
    trade_name: string | null;
    owner: { id: number; name: string } | null;
    team: Team | null;
    relationship_status: string;
};

export default function CompanyPortfolio({
    companies,
    sellers,
    teams,
    filters,
}: {
    companies: {
        data: PortfolioCompany[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    sellers: Seller[];
    teams: Team[];
    filters: {
        q?: string;
        owner_user_id?: string;
        team_id?: string;
        unassigned?: string | boolean;
    };
}) {
    const transfer = useForm({
        company_ids: [] as number[],
        owner_user_id: '',
        team_id: '',
        reason: '',
    });
    const filteredSellers = sellers.filter(
        (seller) =>
            !transfer.data.team_id ||
            seller.team_ids.includes(Number(transfer.data.team_id)),
    );

    function toggleCompany(id: number, checked: boolean) {
        transfer.setData(
            'company_ids',
            checked
                ? [...transfer.data.company_ids, id]
                : transfer.data.company_ids.filter(
                      (selectedId) => selectedId !== id,
                  ),
        );
    }

    function submitTransfer(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const sellerName =
            sellers.find(
                (seller) => seller.id === Number(transfer.data.owner_user_id),
            )?.name ?? 'sem responsável';
        const teamName =
            teams.find((team) => team.id === Number(transfer.data.team_id))
                ?.name ?? 'sem equipe';
        if (
            !window.confirm(
                `Transferir ${transfer.data.company_ids.length} empresas para ${sellerName} · ${teamName}?`,
            )
        ) {
            return;
        }
        transfer.post(transferPortfolio.url(), {
            onSuccess: () => transfer.reset(),
        });
    }

    function applyFilters(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(portfolioIndex.url(), Object.fromEntries(data.entries()), {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <>
            <Head title="Distribuição de carteiras" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="flex items-center gap-2 text-sm font-medium text-primary">
                        <Building2 className="size-4" /> Gestão comercial
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Distribuição de carteiras
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Filtre empresas e transfira a seleção para um vendedor e
                        uma equipe.
                    </p>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 md:grid-cols-4"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="q">Empresa</Label>
                        <Input
                            id="q"
                            name="q"
                            defaultValue={filters.q ?? ''}
                            placeholder="Buscar pelo nome"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="filter-team">Equipe</Label>
                        <select
                            id="filter-team"
                            name="team_id"
                            defaultValue={filters.team_id ?? ''}
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Todas as equipes</option>
                            {teams.map((team) => (
                                <option key={team.id} value={team.id}>
                                    {team.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="filter-seller">Vendedor</Label>
                        <select
                            id="filter-seller"
                            name="owner_user_id"
                            defaultValue={filters.owner_user_id ?? ''}
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Todos os vendedores</option>
                            {sellers.map((seller) => (
                                <option key={seller.id} value={seller.id}>
                                    {seller.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-end gap-3">
                        <label className="flex h-10 items-center gap-2 text-sm">
                            <input
                                name="unassigned"
                                type="checkbox"
                                value="1"
                                defaultChecked={
                                    filters.unassigned === true ||
                                    filters.unassigned === '1'
                                }
                            />
                            Sem responsável
                        </label>
                        <Button type="submit" variant="outline">
                            Filtrar
                        </Button>
                    </div>
                </form>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 px-5 py-4">
                        <div>
                            <h2 className="font-semibold">
                                Empresas ({companies.total})
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Selecione até 100 empresas desta página para uma
                                transferência atômica.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                transfer.setData(
                                    'company_ids',
                                    companies.data.map((company) => company.id),
                                )
                            }
                        >
                            Selecionar página
                        </Button>
                    </div>
                    <div className="divide-y divide-border/60">
                        {companies.data.map((company) => (
                            <label
                                key={company.id}
                                className="flex cursor-pointer items-start gap-3 p-4 hover:bg-muted/30"
                            >
                                <input
                                    type="checkbox"
                                    checked={transfer.data.company_ids.includes(
                                        company.id,
                                    )}
                                    onChange={(event) =>
                                        toggleCompany(
                                            company.id,
                                            event.target.checked,
                                        )
                                    }
                                    className="mt-1 size-4 accent-primary"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {company.trade_name || company.name}
                                    </span>
                                    <span className="mt-1 block text-sm text-muted-foreground">
                                        {company.owner?.name ??
                                            'Sem responsável'}{' '}
                                        · {company.team?.name ?? 'Sem equipe'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {statusLabel(company.relationship_status)}
                                </span>
                            </label>
                        ))}
                        {companies.data.length === 0 && (
                            <p className="p-8 text-center text-sm text-muted-foreground">
                                Nenhuma empresa encontrada.
                            </p>
                        )}
                    </div>
                    {companies.links.length > 3 && (
                        <nav
                            aria-label="Páginas da carteira"
                            className="flex flex-wrap gap-2 border-t border-border/70 p-4"
                        >
                            {companies.links.map((link, index) =>
                                link.url ? (
                                    <Button
                                        key={`${index}-${link.label}`}
                                        asChild
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                    >
                                        <Link href={link.url}>
                                            {paginationLabel(link.label)}
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button
                                        key={`${index}-${link.label}`}
                                        variant="outline"
                                        size="sm"
                                        disabled
                                    >
                                        {paginationLabel(link.label)}
                                    </Button>
                                ),
                            )}
                        </nav>
                    )}
                </section>

                <form
                    onSubmit={submitTransfer}
                    className="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 md:grid-cols-4 md:items-end"
                >
                    <div className="space-y-2">
                        <Label htmlFor="transfer-team">Equipe de destino</Label>
                        <select
                            id="transfer-team"
                            value={transfer.data.team_id}
                            onChange={(event) => {
                                transfer.setData('team_id', event.target.value);
                                const nextTeamId = Number(event.target.value);
                                if (
                                    transfer.data.owner_user_id &&
                                    !sellers
                                        .find(
                                            (seller) =>
                                                seller.id ===
                                                Number(
                                                    transfer.data.owner_user_id,
                                                ),
                                        )
                                        ?.team_ids.includes(nextTeamId)
                                ) {
                                    transfer.setData('owner_user_id', '');
                                }
                            }}
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Sem equipe</option>
                            {teams.map((team) => (
                                <option key={team.id} value={team.id}>
                                    {team.name}
                                </option>
                            ))}
                        </select>
                        {transfer.errors.team_id && (
                            <p className="text-sm text-destructive">
                                {transfer.errors.team_id}
                            </p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="transfer-seller">
                            Vendedor de destino
                        </Label>
                        <select
                            id="transfer-seller"
                            value={transfer.data.owner_user_id}
                            onChange={(event) =>
                                transfer.setData(
                                    'owner_user_id',
                                    event.target.value,
                                )
                            }
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Sem responsável</option>
                            {filteredSellers.map((seller) => (
                                <option key={seller.id} value={seller.id}>
                                    {seller.name}
                                </option>
                            ))}
                        </select>
                        {transfer.errors.owner_user_id && (
                            <p className="text-sm text-destructive">
                                {transfer.errors.owner_user_id}
                            </p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="transfer-reason">
                            Justificativa (opcional)
                        </Label>
                        <Input
                            id="transfer-reason"
                            value={transfer.data.reason}
                            maxLength={500}
                            onChange={(event) =>
                                transfer.setData('reason', event.target.value)
                            }
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        <Button
                            type="submit"
                            disabled={
                                transfer.processing ||
                                transfer.data.company_ids.length === 0
                            }
                        >
                            <ArrowRightLeft className="size-4" /> Transferir{' '}
                            {transfer.data.company_ids.length}
                        </Button>
                        {transfer.errors.company_ids && (
                            <p className="text-xs text-destructive">
                                {transfer.errors.company_ids}
                            </p>
                        )}
                    </div>
                </form>
            </main>
        </>
    );
}

function statusLabel(status: string): string {
    return (
        {
            prospect: 'Prospecto',
            customer: 'Cliente',
            inactive: 'Inativo',
            partner: 'Parceiro',
        }[status] ?? status
    );
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}
