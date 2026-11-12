import { Head, Link, useForm } from '@inertiajs/react';
import { FileUp, Import, ShieldCheck } from 'lucide-react';
import {
    show as showDataImport,
    store as storeDataImport,
} from '@/actions/App/Http/Controllers/DataImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

type ImportRecord = {
    id: number;
    record_type: string;
    status: string;
    original_filename: string;
    journey_mode: string;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    created_rows: number;
    updated_rows: number;
    skipped_rows: number;
    duplicate_rows: number;
    expires_at: string;
    creator: { id: number; name: string } | null;
};

type PageLink = { url: string | null; label: string; active: boolean };
type TeamOption = { id: number; name: string };

const recordTypes = [{ value: 'companies', label: 'Empresas' }];

const statusLabels: Record<string, string> = {
    uploaded: 'Arquivo recebido',
    previewed: 'Prévia disponível',
    mapped: 'Mapeamento salvo',
    validated: 'Validado',
    queued: 'Na fila',
    processing: 'Processando',
    completed: 'Concluído',
    failed: 'Falhou',
    expired: 'Expirado',
};

function dateLabel(value: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

export default function ImportsIndex({
    imports,
    role,
    teams,
}: {
    imports: {
        data: ImportRecord[];
        links: PageLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    role: string;
    teams: TeamOption[];
}) {
    const form = useForm({
        record_type: 'companies',
        journey_mode: 'none',
        journey_template_id: '',
        team_id: '',
        file: null as File | null,
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeDataImport.url(), {
            forceFormData: true,
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Importar dados" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <p className="text-sm font-medium text-primary">
                        Comece pela sua planilha
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Importar dados
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Envie empresas em CSV ou XLSX, revise a prévia e
                        confirme a gravação depois de mapear e validar as
                        linhas.
                    </p>
                </header>

                <section className="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(18rem,0.9fr)]">
                    <form
                        onSubmit={submit}
                        className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7"
                    >
                        <div className="flex items-start gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <FileUp className="size-5" />
                            </span>
                            <div>
                                <h2 className="font-semibold">Novo arquivo</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    O arquivo fica em armazenamento privado e
                                    não cria registros ao ser enviado.
                                </p>
                            </div>
                        </div>

                        <div className="mt-6 grid gap-5">
                            <Field
                                label="O que você está importando?"
                                id="record_type"
                                error={form.errors.record_type}
                            >
                                <select
                                    id="record_type"
                                    value={form.data.record_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'record_type',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {recordTypes.map((recordType) => (
                                        <option
                                            key={recordType.value}
                                            value={recordType.value}
                                        >
                                            {recordType.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Arquivo CSV ou Excel"
                                id="file"
                                error={form.errors.file}
                                required
                            >
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    onChange={(event) =>
                                        form.setData(
                                            'file',
                                            event.currentTarget.files?.item(
                                                0,
                                            ) ?? null,
                                        )
                                    }
                                />
                                <span className="text-xs text-muted-foreground">
                                    CSV ou XLSX, até 10 MB. Não envie dados
                                    pessoais que não sejam necessários.
                                </span>
                            </Field>

                            {(teams.length > 0 || role === 'manager') && (
                                <Field
                                    label="Equipe de destino"
                                    id="team_id"
                                    error={form.errors.team_id}
                                    required={role === 'manager'}
                                >
                                    <select
                                        id="team_id"
                                        value={form.data.team_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'team_id',
                                                event.target.value,
                                            )
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="">Sem equipe</option>
                                        {teams.map((team) => (
                                            <option
                                                key={team.id}
                                                value={team.id}
                                            >
                                                {team.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <div className="flex items-start gap-2 rounded-xl bg-muted/40 p-3 text-sm text-muted-foreground">
                                <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
                                <p>
                                    Esta etapa importa somente empresas e não
                                    inicia jornadas, tarefas ou notificações. A
                                    equipe escolhida é validada no servidor.
                                </p>
                            </div>

                            <Button
                                type="submit"
                                disabled={form.processing || !form.data.file}
                                className="w-full gap-2 sm:w-fit"
                            >
                                {form.processing ? (
                                    <Spinner />
                                ) : (
                                    <Import className="size-4" />
                                )}
                                {form.progress
                                    ? `Enviando ${form.progress.percentage}%`
                                    : 'Enviar para prévia'}
                            </Button>
                        </div>
                    </form>

                    <aside className="rounded-2xl border border-border/70 bg-muted/20 p-5 md:p-7">
                        <h2 className="font-semibold">Como funciona</h2>
                        <ol className="mt-5 grid gap-4 text-sm">
                            {[
                                'Envie a planilha e escolha o tipo de dado.',
                                'Confira as colunas, mapeie os campos e valide as linhas.',
                                'Revise duplicidades e erros antes de confirmar.',
                                'Acompanhe os totais e o resultado do processamento.',
                            ].map((step, index) => (
                                <li key={step} className="flex gap-3">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full border border-border bg-background text-xs font-semibold">
                                        {index + 1}
                                    </span>
                                    <span className="pt-0.5 text-muted-foreground">
                                        {step}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </aside>
                </section>

                <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm shadow-black/[0.02]">
                    <div className="flex flex-col gap-1 border-b border-border/70 px-5 py-4 sm:flex-row sm:items-end sm:justify-between md:px-6">
                        <div>
                            <h2 className="font-semibold">
                                Importações recentes
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {imports.total === 0
                                    ? 'Os lotes enviados aparecerão aqui.'
                                    : `${imports.total} lote${imports.total === 1 ? '' : 's'}`}
                            </p>
                        </div>
                        {imports.total > 0 && imports.from !== null && (
                            <span className="text-xs text-muted-foreground">
                                Exibindo {imports.from}–{imports.to} de{' '}
                                {imports.total}
                            </span>
                        )}
                    </div>

                    {imports.data.length === 0 ? (
                        <div className="flex flex-col items-center px-5 py-12 text-center">
                            <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <FileUp className="size-5" />
                            </span>
                            <p className="mt-4 font-medium">
                                Nenhuma importação ainda
                            </p>
                            <p className="mt-1 max-w-md text-sm text-muted-foreground">
                                Envie sua primeira planilha para começar a
                                preparar os dados da carteira.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[42rem] text-left text-sm">
                                    <thead className="bg-muted/25 text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-5 py-3 font-medium">
                                                Arquivo
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Tipo
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Status
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Jornadas
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Expira
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {imports.data.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="border-t border-border/60"
                                            >
                                                <td className="px-5 py-3 font-medium">
                                                    <Link
                                                        href={showDataImport.url(
                                                            {
                                                                dataImport:
                                                                    item.id,
                                                            },
                                                        )}
                                                        className="hover:text-primary hover:underline"
                                                    >
                                                        {item.original_filename}
                                                    </Link>
                                                </td>
                                                <td className="px-5 py-3 text-muted-foreground">
                                                    {recordTypes.find(
                                                        (type) =>
                                                            type.value ===
                                                            item.record_type,
                                                    )?.label ??
                                                        item.record_type}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {statusLabels[
                                                        item.status
                                                    ] ?? item.status}
                                                </td>
                                                <td className="px-5 py-3 text-muted-foreground">
                                                    Não iniciadas
                                                </td>
                                                <td className="px-5 py-3 text-muted-foreground">
                                                    {dateLabel(item.expires_at)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <nav
                                aria-label="Paginação de importações"
                                className="flex flex-wrap gap-2 border-t border-border/60 p-4"
                            >
                                {imports.links.map((link, index) => (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url ?? '#'}
                                        aria-current={
                                            link.active ? 'page' : undefined
                                        }
                                        aria-disabled={link.url === null}
                                        className={`rounded-md border px-3 py-1.5 text-xs ${link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'} ${link.url === null ? 'pointer-events-none opacity-50' : 'hover:bg-muted'}`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ))}
                            </nav>
                        </>
                    )}
                </section>
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

ImportsIndex.layout = {
    breadcrumbs: [
        { title: 'Importar dados', href: '/imports' },
    ] satisfies BreadcrumbItem[],
};
