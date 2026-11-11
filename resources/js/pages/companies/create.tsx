import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2, MapPin } from 'lucide-react';
import {
    index as companiesIndex,
    store as storeCompany,
} from '@/actions/App/Http/Controllers/CompanyController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Status = { value: string; label: string };

export default function CompanyCreate({
    members,
    statuses,
}: {
    members: Member[];
    statuses: Status[];
}) {
    const form = useForm({
        name: '',
        trade_name: '',
        legal_name: '',
        tax_id: '',
        segment: '',
        phone: '',
        email: '',
        website: '',
        city: '',
        state: '',
        address: '',
        relationship_status: 'prospect',
        lead_source: '',
        owner_user_id: '',
        notes: '',
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeCompany.url());
    }

    return (
        <>
            <Head title="Nova empresa" />
            <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-5 py-8 md:px-8">
                <div>
                    <Link
                        href={companiesIndex.url()}
                        className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> Voltar para empresas
                    </Link>
                    <div className="mt-5 flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                            <Building2 className="size-5" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-semibold tracking-tight">
                                Cadastrar empresa
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Inclua uma empresa na carteira e defina quem
                                fará o acompanhamento.
                            </p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <SectionHeading
                            icon={Building2}
                            title="Dados da empresa"
                            detail="Informações principais do relacionamento comercial."
                        />
                        <div className="mt-6 grid gap-x-5 gap-y-4 md:grid-cols-2">
                            <Field
                                label="Nome da empresa"
                                id="name"
                                error={form.errors.name}
                                required
                            >
                                <Input
                                    id="name"
                                    autoFocus
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder="Ex.: Escritório Horizonte"
                                />
                            </Field>
                            <Field
                                label="Nome fantasia"
                                id="trade_name"
                                error={form.errors.trade_name}
                            >
                                <Input
                                    id="trade_name"
                                    value={form.data.trade_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'trade_name',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Como a empresa é conhecida"
                                />
                            </Field>
                            <Field
                                label="Razão social"
                                id="legal_name"
                                error={form.errors.legal_name}
                            >
                                <Input
                                    id="legal_name"
                                    value={form.data.legal_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'legal_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="CNPJ"
                                id="tax_id"
                                error={form.errors.tax_id}
                            >
                                <Input
                                    id="tax_id"
                                    value={form.data.tax_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'tax_id',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Opcional"
                                />
                            </Field>
                            <Field
                                label="Segmento"
                                id="segment"
                                error={form.errors.segment}
                            >
                                <Input
                                    id="segment"
                                    value={form.data.segment}
                                    onChange={(event) =>
                                        form.setData(
                                            'segment',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Ex.: Arquitetura"
                                />
                            </Field>
                            <Field
                                label="Origem da prospecção"
                                id="lead_source"
                                error={form.errors.lead_source}
                            >
                                <Input
                                    id="lead_source"
                                    value={form.data.lead_source}
                                    onChange={(event) =>
                                        form.setData(
                                            'lead_source',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Indicação, evento, pesquisa…"
                                />
                            </Field>
                            <Field
                                label="Telefone"
                                id="phone"
                                error={form.errors.phone}
                            >
                                <Input
                                    id="phone"
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(event) =>
                                        form.setData(
                                            'phone',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="E-mail"
                                id="email"
                                error={form.errors.email}
                            >
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Site"
                                id="website"
                                error={form.errors.website}
                            >
                                <Input
                                    id="website"
                                    type="url"
                                    value={form.data.website}
                                    onChange={(event) =>
                                        form.setData(
                                            'website',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="https://"
                                />
                            </Field>
                            <Field
                                label="Situação comercial"
                                id="relationship_status"
                                error={form.errors.relationship_status}
                                required
                            >
                                <select
                                    id="relationship_status"
                                    value={form.data.relationship_status}
                                    onChange={(event) =>
                                        form.setData(
                                            'relationship_status',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {statuses.map((status) => (
                                        <option
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <SectionHeading
                            icon={MapPin}
                            title="Localização e acompanhamento"
                            detail="Atribua um responsável para manter o próximo contato em dia."
                        />
                        <div className="mt-6 grid gap-x-5 gap-y-4 md:grid-cols-2">
                            <Field
                                label="Cidade"
                                id="city"
                                error={form.errors.city}
                            >
                                <Input
                                    id="city"
                                    value={form.data.city}
                                    onChange={(event) =>
                                        form.setData('city', event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Estado"
                                id="state"
                                error={form.errors.state}
                            >
                                <Input
                                    id="state"
                                    maxLength={2}
                                    value={form.data.state}
                                    onChange={(event) =>
                                        form.setData(
                                            'state',
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    placeholder="SP"
                                />
                            </Field>
                            <Field
                                label="Endereço"
                                id="address"
                                error={form.errors.address}
                                className="md:col-span-2"
                            >
                                <textarea
                                    id="address"
                                    value={form.data.address}
                                    onChange={(event) =>
                                        form.setData(
                                            'address',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                            <Field
                                label="Vendedor responsável"
                                id="owner_user_id"
                                error={form.errors.owner_user_id}
                                className="md:col-span-2"
                            >
                                <select
                                    id="owner_user_id"
                                    value={form.data.owner_user_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'owner_user_id',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        Sem responsável definido
                                    </option>
                                    {members.map((member) => (
                                        <option
                                            key={member.id}
                                            value={member.id}
                                        >
                                            {member.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                label="Anotações iniciais"
                                id="notes"
                                error={form.errors.notes}
                                className="md:col-span-2"
                            >
                                <textarea
                                    id="notes"
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    rows={4}
                                    placeholder="Informações comerciais relevantes para a equipe"
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                        </div>
                    </section>

                    <div className="flex flex-col-reverse justify-end gap-3 sm:flex-row">
                        <Button
                            asChild
                            type="button"
                            variant="outline"
                            className="rounded-lg"
                        >
                            <Link href={companiesIndex.url()}>Cancelar</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="gap-2 rounded-lg px-5"
                        >
                            {form.processing && <Spinner />} Salvar empresa
                        </Button>
                    </div>
                </form>
            </main>
        </>
    );
}

function Field({
    label,
    id,
    error,
    required = false,
    className = '',
    children,
}: {
    label: string;
    id: string;
    error?: string;
    required?: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={`grid gap-2 ${className}`}>
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function SectionHeading({
    icon: Icon,
    title,
    detail,
}: {
    icon: typeof Building2;
    title: string;
    detail: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-4" />
            </span>
            <div>
                <h2 className="font-semibold">{title}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{detail}</p>
            </div>
        </div>
    );
}

CompanyCreate.layout = {
    breadcrumbs: [
        { title: 'Empresas', href: companiesIndex.url() },
        { title: 'Nova empresa', href: companiesIndex.url() },
    ] satisfies BreadcrumbItem[],
};
