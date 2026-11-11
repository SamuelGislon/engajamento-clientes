import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Mail,
    Plus,
    Search,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    create as createContact,
    index as contactsIndex,
} from '@/actions/App/Http/Controllers/ContactController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

type Contact = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    job_title: string | null;
    companies: { id: number; name: string; trade_name: string | null }[];
};

type PageLink = { url: string | null; label: string; active: boolean };

export default function ContactsIndex({
    contacts,
    filters,
}: {
    contacts: {
        data: Contact[];
        links: PageLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { q?: string };
}) {
    const [query, setQuery] = useState(filters.q ?? '');

    return (
        <>
            <Head title="Profissionais" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-sm font-medium text-primary">
                            Pessoas e vínculos
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            Profissionais
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Entenda com quem falar em cada empresa e qual é a
                            responsabilidade de cada pessoa.
                        </p>
                    </div>
                    <Button
                        asChild
                        className="h-10 gap-2 self-start rounded-lg px-4 sm:self-auto"
                    >
                        <Link href={createContact.url()}>
                            <Plus className="size-4" /> Novo profissional
                        </Link>
                    </Button>
                </header>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm shadow-black/[0.02]">
                    <form
                        action={contactsIndex.url()}
                        method="get"
                        className="flex gap-3 border-b border-border/70 p-4"
                    >
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                name="q"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Buscar por nome ou e-mail"
                                className="h-10 rounded-lg pl-9"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            className="h-10 rounded-lg px-4"
                        >
                            Buscar
                        </Button>
                    </form>
                    {contacts.data.length === 0 ? (
                        <div className="px-6 py-16 text-center">
                            <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <UserRound className="size-5" />
                            </span>
                            <h2 className="mt-4 font-semibold">
                                Nenhum profissional encontrado
                            </h2>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                                Cadastre pessoas e associe cada profissional às
                                empresas com as quais se relaciona.
                            </p>
                            <Button asChild className="mt-5 rounded-lg">
                                <Link href={createContact.url()}>
                                    Cadastrar profissional
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="divide-y divide-border/60">
                                {contacts.data.map((contact) => (
                                    <article
                                        key={contact.id}
                                        className="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between md:px-6"
                                    >
                                        <div className="flex min-w-0 items-start gap-3">
                                            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#edf4ef] text-[#315c4c] dark:bg-emerald-950 dark:text-emerald-200">
                                                <UserRound className="size-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <h2 className="truncate text-sm font-semibold">
                                                    {contact.name}
                                                </h2>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {contact.job_title ||
                                                        'Cargo não informado'}
                                                </p>
                                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                    {contact.email && (
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <Mail className="size-3.5" />
                                                            {contact.email}
                                                        </span>
                                                    )}
                                                    {contact.phone && (
                                                        <span>
                                                            {contact.phone}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                                            {contact.companies.map(
                                                (company) => (
                                                    <span
                                                        key={company.id}
                                                        className="inline-flex max-w-[220px] items-center gap-1.5 rounded-full bg-muted px-3 py-1.5 text-xs font-medium"
                                                    >
                                                        <UsersRound className="size-3.5 shrink-0 text-muted-foreground" />
                                                        <span className="truncate">
                                                            {company.trade_name ||
                                                                company.name}
                                                        </span>
                                                    </span>
                                                ),
                                            )}
                                            {contact.companies.length === 0 && (
                                                <span className="text-xs text-muted-foreground">
                                                    Sem empresa associada
                                                </span>
                                            )}
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <div className="flex flex-col gap-3 border-t border-border/70 px-5 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    {contacts.from ?? 0}–{contacts.to ?? 0} de{' '}
                                    {contacts.total} profissionais
                                </span>
                                <nav
                                    aria-label="Paginação de profissionais"
                                    className="flex flex-wrap gap-1"
                                >
                                    {contacts.links.map((link, index) =>
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
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <ArrowRight className="size-3.5" /> Abra uma empresa para
                    ver o histórico de cada relacionamento e as
                    responsabilidades vinculadas.
                </p>
            </main>
        </>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Profissionais',
            href: contactsIndex.url(),
        } satisfies BreadcrumbItem,
    ],
};
