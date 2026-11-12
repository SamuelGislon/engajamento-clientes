import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { update as updateSale } from '@/actions/App/Http/Controllers/SaleController';
import { show as showCompany } from '@/actions/App/Http/Controllers/CompanyController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Sale = {
    id: number;
    description: string;
    amount: string;
    currency: string;
    sold_at: string;
    sold_at_local: string;
    notes: string | null;
    company: { id: number; name: string };
    seller: { id: number; name: string };
};

export default function SalesEdit({
    sale,
}: {
    sale: Sale;
    salesTimezone: string;
}) {
    const form = useForm({
        description: sale.description,
        amount: sale.amount,
        currency: sale.currency,
        sold_at: sale.sold_at_local,
        notes: sale.notes ?? '',
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(updateSale.url(sale.id));
    }

    return (
        <>
            <Head title="Editar venda" />
            <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-5 py-8 md:px-8">
                <Link
                    href={showCompany.url(sale.company.id)}
                    className="inline-flex w-fit items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Voltar para{' '}
                    {sale.company.name}
                </Link>
                <header>
                    <p className="text-sm font-medium text-primary">
                        Histórico comercial
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Editar venda
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Registrada por {sale.seller.name}. A data não pode ser
                        futura e o valor deve ser positivo.
                    </p>
                </header>

                <form
                    onSubmit={submit}
                    className="grid gap-5 rounded-2xl border border-border/70 bg-card p-5 md:p-7"
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Descrição
                        <Input
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            maxLength={255}
                            required
                        />
                        <InputError message={form.errors.description} />
                    </label>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm font-medium">
                            Valor
                            <Input
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.amount} />
                        </label>
                        <label className="grid gap-2 text-sm font-medium">
                            Moeda
                            <Input value="BRL" disabled />
                        </label>
                    </div>
                    <label className="grid gap-2 text-sm font-medium">
                        Data da compra
                        <Input
                            type="datetime-local"
                            value={form.data.sold_at}
                            onChange={(event) =>
                                form.setData('sold_at', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.sold_at} />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Observações
                        <textarea
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            maxLength={5000}
                            rows={4}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        <InputError message={form.errors.notes} />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Salvar alterações
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={showCompany.url(sale.company.id)}>
                                Cancelar
                            </Link>
                        </Button>
                    </div>
                </form>
            </main>
        </>
    );
}

SalesEdit.layout = {
    breadcrumbs: [{ title: 'Vendas', href: '/sales' }],
} satisfies { breadcrumbs: BreadcrumbItem[] };
