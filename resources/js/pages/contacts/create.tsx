import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ContactRound, UserRound } from 'lucide-react';
import { useState } from 'react';
import {
    create as createContactAction,
    index as contactsIndex,
    store as storeContact,
} from '@/actions/App/Http/Controllers/ContactController';
import { index as companiesIndex } from '@/actions/App/Http/Controllers/CompanyController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

type Company = { id: number; name: string; trade_name: string | null };
type Role = { value: string; label: string };

export default function ContactCreate({
    companies,
    selectedCompanyId,
    roles,
}: {
    companies: Company[];
    selectedCompanyId: number | null;
    roles: Role[];
}) {
    const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        whatsapp: '',
        job_title: '',
        preferred_channel: '',
        contact_preferences: '',
        professional_interests: '',
        notes: '',
        company_id: String(selectedCompanyId ?? ''),
        roles: selectedRoles,
        is_primary: false,
        relationship_notes: '',
    });

    function toggleRole(value: string) {
        const nextRoles = selectedRoles.includes(value)
            ? selectedRoles.filter((role) => role !== value)
            : [...selectedRoles, value];
        setSelectedRoles(nextRoles);
        form.setData('roles', nextRoles);
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeContact.url());
    }

    return (
        <>
            <Head title="Novo profissional" />
            <main className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-5 py-8 md:px-8">
                <div>
                    <Link
                        href={companiesIndex.url()}
                        className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> Voltar
                    </Link>
                    <div className="mt-5 flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                            <UserRound className="size-5" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-semibold tracking-tight">
                                Cadastrar profissional
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Registre apenas os dados profissionais
                                necessários para o relacionamento.
                            </p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <SectionHeading
                            title="Dados profissionais"
                            detail="Os campos de preferências são opcionais e podem ser atualizados depois."
                        />
                        <div className="mt-6 grid gap-4 md:grid-cols-2">
                            <Field
                                label="Nome completo"
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
                                    placeholder="Nome do profissional"
                                />
                            </Field>
                            <Field
                                label="Cargo"
                                id="job_title"
                                error={form.errors.job_title}
                            >
                                <Input
                                    id="job_title"
                                    value={form.data.job_title}
                                    onChange={(event) =>
                                        form.setData(
                                            'job_title',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Ex.: Responsável por orçamentos"
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
                                label="WhatsApp"
                                id="whatsapp"
                                error={form.errors.whatsapp}
                            >
                                <Input
                                    id="whatsapp"
                                    type="tel"
                                    value={form.data.whatsapp}
                                    onChange={(event) =>
                                        form.setData(
                                            'whatsapp',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Canal preferido"
                                id="preferred_channel"
                                error={form.errors.preferred_channel}
                            >
                                <select
                                    id="preferred_channel"
                                    value={form.data.preferred_channel}
                                    onChange={(event) =>
                                        form.setData(
                                            'preferred_channel',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">Não informado</option>
                                    <option value="call">Ligação</option>
                                    <option value="message">Mensagem</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="email">E-mail</option>
                                    <option value="meeting">Reunião</option>
                                </select>
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <SectionHeading
                            title="Vínculo com a empresa"
                            detail="Uma pessoa pode participar de diferentes empresas e ter papéis distintos em cada vínculo."
                        />
                        <div className="mt-6 grid gap-4">
                            <Field
                                label="Empresa"
                                id="company_id"
                                error={form.errors.company_id}
                                required
                            >
                                <select
                                    id="company_id"
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_id',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        Selecione uma empresa
                                    </option>
                                    {companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.trade_name || company.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <div>
                                <Label>Responsabilidades nessa empresa</Label>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {roles.map((role) => (
                                        <label
                                            key={role.value}
                                            className="flex cursor-pointer items-center gap-3 rounded-lg border border-border/70 px-3 py-2.5 text-sm hover:bg-muted/30"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selectedRoles.includes(
                                                    role.value,
                                                )}
                                                onChange={() =>
                                                    toggleRole(role.value)
                                                }
                                                className="size-4 rounded border-input accent-[#315c4c]"
                                            />
                                            {role.label}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={form.errors.roles} />
                                {form.errors['roles.0'] && (
                                    <InputError
                                        message={form.errors['roles.0']}
                                    />
                                )}
                            </div>
                            <label className="flex items-start gap-3 rounded-lg bg-muted/35 p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_primary}
                                    onChange={(event) =>
                                        form.setData(
                                            'is_primary',
                                            event.target.checked,
                                        )
                                    }
                                    className="mt-0.5 size-4 accent-[#315c4c]"
                                />
                                <span>
                                    <span className="font-medium">
                                        Contato principal
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Marque se essa pessoa deve receber o
                                        próximo contato comercial.
                                    </span>
                                </span>
                            </label>
                            <Field
                                label="Observações sobre o vínculo"
                                id="relationship_notes"
                                error={form.errors.relationship_notes}
                            >
                                <textarea
                                    id="relationship_notes"
                                    value={form.data.relationship_notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'relationship_notes',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    placeholder="Contexto específico dessa empresa"
                                />
                            </Field>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <SectionHeading
                            title="Preferências e contexto"
                            detail="Use informações pertinentes à atividade profissional. Evite dados pessoais sensíveis."
                        />
                        <div className="mt-6 grid gap-4">
                            <Field
                                label="Preferências de contato"
                                id="contact_preferences"
                                error={form.errors.contact_preferences}
                            >
                                <textarea
                                    id="contact_preferences"
                                    value={form.data.contact_preferences}
                                    onChange={(event) =>
                                        form.setData(
                                            'contact_preferences',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                            <Field
                                label="Interesses profissionais"
                                id="professional_interests"
                                error={form.errors.professional_interests}
                            >
                                <textarea
                                    id="professional_interests"
                                    value={form.data.professional_interests}
                                    onChange={(event) =>
                                        form.setData(
                                            'professional_interests',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                            <Field
                                label="Observações comerciais"
                                id="notes"
                                error={form.errors.notes}
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
                                    rows={3}
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
                            disabled={form.processing || companies.length === 0}
                            className="gap-2 rounded-lg px-5"
                        >
                            {form.processing && <Spinner />} Salvar profissional
                        </Button>
                    </div>
                    {companies.length === 0 && (
                        <p className="text-right text-sm text-destructive">
                            Cadastre uma empresa antes de adicionar um
                            profissional.
                        </p>
                    )}
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
    children,
}: {
    label: string;
    id: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function SectionHeading({ title, detail }: { title: string; detail: string }) {
    return (
        <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <ContactRound className="size-4" />
            </span>
            <div>
                <h2 className="font-semibold">{title}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{detail}</p>
            </div>
        </div>
    );
}

ContactCreate.layout = {
    breadcrumbs: [
        { title: 'Profissionais', href: contactsIndex.url() },
        { title: 'Novo profissional', href: createContactAction.url() },
    ] satisfies BreadcrumbItem[],
};
