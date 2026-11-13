import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    FileSpreadsheet,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import {
    confirm as confirmDataImport,
    index as importsIndex,
    saveMapping as saveImportMapping,
    validateImport as validateDataImport,
    resume as resumeDataImport,
} from '@/actions/App/Http/Controllers/DataImportController';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';
import { useEffect } from 'react';

type Preview = {
    sheetName: string;
    headers: string[];
    rows: string[][];
    totalRows: number;
    malformedRows: number[];
};

type ImportRecord = {
    id: number;
    record_type: string;
    status: string;
    original_filename: string;
    journey_mode: string;
    journey_template: { id: number; name: string } | null;
    mapping: Record<string, number> | null;
    total_rows: number;
    valid_rows: number;
    processed_rows: number;
    last_error: string | null;
    invalid_rows: number;
    duplicate_rows: number;
    created_rows: number;
    updated_rows: number;
    skipped_rows: number;
    validation_errors: { line: number; type: string; message: string }[];
    team: { id: number; name: string } | null;
    expires_at: string;
};

type FieldOption = { label: string; required: boolean };
type JourneyTemplateOption = { id: number; name: string; is_default: boolean };

function dateLabel(value: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

export default function ImportShow({
    import: importRecord,
    preview,
    fileError,
    fields,
    journeyTemplates,
}: {
    import: ImportRecord;
    preview: Preview | null;
    fileError: string | null;
    fields: Record<string, FieldOption>;
    journeyTemplates: JourneyTemplateOption[];
}) {
    const form = useForm({
        mapping: Object.fromEntries(
            Object.entries(fields).map(([field]) => [
                field,
                importRecord.mapping?.[field] === undefined
                    ? ''
                    : String(importRecord.mapping[field]),
            ]),
        ) as Record<string, string>,
        journey_mode: importRecord.journey_mode,
        journey_template_id: importRecord.journey_template
            ? String(importRecord.journey_template.id)
            : '',
    });
    const actionForm = useForm({});
    const isQueueActive =
        importRecord.status === 'queued' ||
        importRecord.status === 'processing';
    const { start: startPolling, stop: stopPolling } = usePoll(
        2000,
        { only: ['import'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (isQueueActive) {
            startPolling();
        } else {
            stopPolling();
        }

        return stopPolling;
    }, [isQueueActive, startPolling, stopPolling]);
    const actionErrors = Object.values(actionForm.errors).filter(
        (message): message is string => typeof message === 'string',
    );

    function saveMapping(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(saveImportMapping.url({ dataImport: importRecord.id }), {
            preserveScroll: true,
        });
    }

    function validateRows() {
        actionForm.post(
            validateDataImport.url({ dataImport: importRecord.id }),
            { preserveScroll: true },
        );
    }

    function confirmRows() {
        actionForm.post(
            confirmDataImport.url({ dataImport: importRecord.id }),
            { preserveScroll: true },
        );
    }

    function resumeRows() {
        actionForm.post(resumeDataImport.url({ dataImport: importRecord.id }), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Prévia da importação" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-8 md:px-8">
                <header>
                    <Button
                        asChild
                        variant="ghost"
                        className="-ml-3 gap-2 text-muted-foreground"
                    >
                        <Link href={importsIndex.url()}>
                            <ArrowLeft className="size-4" /> Voltar às
                            importações
                        </Link>
                    </Button>
                    <div className="mt-4 flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <FileSpreadsheet className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-primary">
                                Prévia da planilha
                            </p>
                            <h1 className="mt-1 truncate text-2xl font-semibold tracking-tight sm:text-3xl">
                                {importRecord.original_filename}
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Os dados ainda não foram gravados na sua
                                carteira. O arquivo temporário expira em{' '}
                                {dateLabel(importRecord.expires_at)}.
                            </p>
                            {importRecord.team && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Equipe de destino: {importRecord.team.name}
                                </p>
                            )}
                            {importRecord.record_type === 'companies' && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Jornadas:{' '}
                                    {importRecord.journey_mode === 'none'
                                        ? 'não iniciar'
                                        : importRecord.journey_mode ===
                                            'default'
                                          ? 'jornada padrão'
                                          : (importRecord.journey_template
                                                ?.name ?? 'jornada específica')}
                                </p>
                            )}
                        </div>
                    </div>
                </header>

                {['queued', 'processing', 'failed'].includes(
                    importRecord.status,
                ) && (
                    <section
                        aria-live="polite"
                        className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7"
                    >
                        <h2 className="font-semibold">
                            {importRecord.status === 'queued'
                                ? 'Importação na fila'
                                : importRecord.status === 'processing'
                                  ? 'Importação em andamento'
                                  : 'Importação interrompida'}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {importRecord.status === 'queued'
                                ? 'O processamento começará assim que um worker estiver disponível.'
                                : importRecord.status === 'processing'
                                  ? 'Esta página atualiza o progresso automaticamente.'
                                  : (importRecord.last_error ??
                                    'O lote pode ser retomado a partir das linhas pendentes.')}
                        </p>
                        {importRecord.status !== 'failed' && (
                            <div className="mt-5 grid gap-2">
                                <progress
                                    aria-label="Progresso da importação"
                                    className="h-3 w-full accent-primary"
                                    max={Math.max(importRecord.total_rows, 1)}
                                    value={Math.min(
                                        importRecord.processed_rows,
                                        importRecord.total_rows,
                                    )}
                                />
                                <p className="text-sm text-muted-foreground">
                                    {importRecord.processed_rows} de{' '}
                                    {importRecord.total_rows} linhas processadas
                                </p>
                            </div>
                        )}
                        {actionErrors.length > 0 && (
                            <div
                                role="alert"
                                className="mt-4 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                            >
                                {actionErrors.map((message, index) => (
                                    <p key={`${message}-${index}`}>{message}</p>
                                ))}
                            </div>
                        )}
                        {importRecord.status === 'failed' && (
                            <Button
                                type="button"
                                onClick={resumeRows}
                                disabled={actionForm.processing}
                                className="mt-4"
                            >
                                {actionForm.processing
                                    ? 'Retomando…'
                                    : 'Retomar linhas pendentes'}
                            </Button>
                        )}
                    </section>
                )}

                {importRecord.status === 'expired' && (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-500/25 bg-amber-500/5 p-4 text-sm text-muted-foreground"
                    >
                        O arquivo temporário expirou. Envie a planilha novamente
                        para iniciar outra importação.
                    </p>
                )}

                {fileError ? (
                    <section
                        role="alert"
                        className="flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-5 text-sm"
                    >
                        <TriangleAlert className="mt-0.5 size-5 shrink-0 text-destructive" />
                        <div>
                            <h2 className="font-semibold text-destructive">
                                Não foi possível ler este arquivo
                            </h2>
                            <p className="mt-1 text-muted-foreground">
                                {fileError}
                            </p>
                        </div>
                    </section>
                ) : preview ? (
                    <>
                        <section className="grid gap-4 sm:grid-cols-3">
                            <Summary
                                label="Registros encontrados"
                                value={preview.totalRows}
                            />
                            <Summary
                                label="Colunas"
                                value={preview.headers.length}
                            />
                            <Summary
                                label="Amostra exibida"
                                value={preview.rows.length}
                            />
                        </section>

                        {preview.malformedRows.length > 0 && (
                            <p
                                role="status"
                                className="rounded-xl border border-amber-500/25 bg-amber-500/5 p-4 text-sm text-muted-foreground"
                            >
                                {preview.malformedRows.length} linhas têm
                                valores além das colunas do cabeçalho e serão
                                rejeitadas. Primeiras linhas:{' '}
                                {preview.malformedRows.slice(0, 5).join(', ')}
                                {preview.malformedRows.length > 5 ? '…' : ''}.
                            </p>
                        )}

                        <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm shadow-black/[0.02]">
                            <div className="flex flex-col gap-1 border-b border-border/70 px-5 py-4 sm:flex-row sm:items-end sm:justify-between md:px-6">
                                <div>
                                    <h2 className="font-semibold">
                                        Conteúdo da primeira planilha
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Aba: {preview.sheetName}. Exibimos no
                                        máximo as primeiras 10 linhas.
                                    </p>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {preview.headers.length} colunas ·{' '}
                                    {preview.totalRows} linhas não vazias
                                </p>
                            </div>
                            {preview.rows.length === 0 ? (
                                <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                                    O cabeçalho foi encontrado, mas não há
                                    registros preenchidos abaixo dele.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[42rem] text-left text-sm">
                                        <thead className="bg-muted/25 text-xs text-muted-foreground">
                                            <tr>
                                                {preview.headers.map(
                                                    (header, index) => (
                                                        <th
                                                            key={`${header}-${index}`}
                                                            className="max-w-64 px-4 py-3 font-medium"
                                                        >
                                                            {header}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {preview.rows.map(
                                                (row, rowIndex) => (
                                                    <tr
                                                        key={rowIndex}
                                                        className="border-t border-border/60"
                                                    >
                                                        {preview.headers.map(
                                                            (
                                                                _,
                                                                columnIndex,
                                                            ) => (
                                                                <td
                                                                    key={`${rowIndex}-${columnIndex}`}
                                                                    className="max-w-64 truncate px-4 py-3"
                                                                >
                                                                    {row[
                                                                        columnIndex
                                                                    ] || (
                                                                        <span className="text-muted-foreground/60">
                                                                            —
                                                                        </span>
                                                                    )}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        <p className="flex items-start gap-2 rounded-xl bg-muted/35 p-4 text-sm text-muted-foreground">
                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
                            A prévia é somente para conferência. O arquivo é
                            lido em armazenamento privado e não inicia jornadas
                            nem cria tarefas.
                        </p>
                    </>
                ) : null}

                {!fileError &&
                    preview &&
                    ['uploaded', 'previewed', 'mapped', 'validated'].includes(
                        importRecord.status,
                    ) && (
                        <form
                            onSubmit={saveMapping}
                            className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7"
                        >
                            <div>
                                <h2 className="font-semibold">
                                    Mapear colunas
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Escolha a coluna da planilha que corresponde
                                    a cada campo.{' '}
                                    {importRecord.record_type === 'contacts'
                                        ? 'Associe cada linha a uma empresa acessível pelo CNPJ ou nome exato. Linhas com o mesmo e-mail ou nome e telefone iguais podem criar um contato com vários vínculos, se os dados pessoais forem compatíveis.'
                                        : importRecord.record_type === 'sales'
                                          ? 'Associe a venda a uma empresa acessível e a um vendedor ativo autorizado. Se houver, mapeie um identificador externo estável para evitar reimportação entre lotes. Sem ele, linhas iguais são mantidas separadamente. O valor aceita ponto ou vírgula decimal e datas sem fuso são interpretadas no fuso da organização.'
                                          : 'O nome da empresa é obrigatório.'}
                                </p>
                            </div>
                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                {Object.entries(fields).map(
                                    ([field, option]) => (
                                        <div key={field} className="grid gap-2">
                                            <Label htmlFor={`mapping-${field}`}>
                                                {option.label}
                                                {option.required && (
                                                    <span className="ml-1 text-destructive">
                                                        *
                                                    </span>
                                                )}
                                            </Label>
                                            <select
                                                id={`mapping-${field}`}
                                                value={
                                                    form.data.mapping[field] ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    form.setData('mapping', {
                                                        ...form.data.mapping,
                                                        [field]:
                                                            event.target.value,
                                                    })
                                                }
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="">
                                                    Não mapear este campo
                                                </option>
                                                {preview.headers.map(
                                                    (header, columnIndex) => (
                                                        <option
                                                            key={`${header}-${columnIndex}`}
                                                            value={columnIndex}
                                                        >
                                                            {header}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `mapping.${field}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ),
                                )}
                            </div>
                            {importRecord.record_type === 'companies' && (
                                <div className="mt-5 grid gap-4 rounded-xl border border-border/70 p-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="journey_mode">
                                            Jornadas para prospectos novos
                                        </Label>
                                        <select
                                            id="journey_mode"
                                            value={form.data.journey_mode}
                                            onChange={(event) => {
                                                form.setData(
                                                    'journey_mode',
                                                    event.target.value,
                                                );
                                                if (
                                                    event.target.value !==
                                                    'template'
                                                ) {
                                                    form.setData(
                                                        'journey_template_id',
                                                        '',
                                                    );
                                                }
                                            }}
                                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="none">
                                                Não iniciar jornadas
                                            </option>
                                            <option value="default">
                                                Utilizar a jornada padrão
                                            </option>
                                            <option value="template">
                                                Selecionar uma jornada
                                                específica
                                            </option>
                                        </select>
                                        <InputError
                                            message={form.errors.journey_mode}
                                        />
                                    </div>
                                    {form.data.journey_mode === 'template' && (
                                        <div className="grid content-start gap-2">
                                            <Label htmlFor="journey_template_id">
                                                Jornada de prospecção
                                            </Label>
                                            <select
                                                id="journey_template_id"
                                                value={
                                                    form.data
                                                        .journey_template_id
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'journey_template_id',
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="">
                                                    Selecione um modelo ativo
                                                </option>
                                                {importRecord.journey_template &&
                                                    !journeyTemplates.some(
                                                        (template) =>
                                                            template.id ===
                                                            importRecord
                                                                .journey_template
                                                                ?.id,
                                                    ) && (
                                                        <option
                                                            value={String(
                                                                importRecord
                                                                    .journey_template
                                                                    .id,
                                                            )}
                                                            disabled
                                                        >
                                                            {
                                                                importRecord
                                                                    .journey_template
                                                                    .name
                                                            }{' '}
                                                            (desativada)
                                                        </option>
                                                    )}
                                                {journeyTemplates.map(
                                                    (template) => (
                                                        <option
                                                            key={template.id}
                                                            value={String(
                                                                template.id,
                                                            )}
                                                        >
                                                            {template.name}
                                                            {template.is_default
                                                                ? ' (padrão)'
                                                                : ''}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    form.errors
                                                        .journey_template_id
                                                }
                                            />
                                        </div>
                                    )}
                                    <p className="text-xs text-muted-foreground sm:col-span-2">
                                        Só empresas novas elegíveis como
                                        prospectos iniciam uma jornada. Você
                                        pode alterar esta opção antes de
                                        confirmar a importação.
                                    </p>
                                </div>
                            )}
                            {form.errors.mapping && (
                                <p
                                    role="alert"
                                    className="mt-3 text-sm text-destructive"
                                >
                                    {form.errors.mapping}
                                </p>
                            )}
                            <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-xs text-muted-foreground">
                                    Salvar o mapeamento e a opção de jornada não
                                    grava dados comerciais na carteira.
                                </p>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing && (
                                        <span className="mr-2">Salvando…</span>
                                    )}
                                    Salvar mapeamento
                                </Button>
                            </div>
                        </form>
                    )}

                {importRecord.status === 'mapped' ||
                importRecord.status === 'validated' ? (
                    <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02] md:p-7">
                        <h2 className="font-semibold">Validar e confirmar</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            A validação lê todas as linhas, identifica erros e
                            duplicidades reconhecíveis pelas regras de cada
                            tipo. Linhas duplicadas ou inválidas serão
                            ignoradas; os registros existentes não serão
                            alterados. Vendas sem identificador externo são
                            tratadas como transações distintas.
                        </p>
                        <div className="mt-5 grid gap-3 sm:grid-cols-4">
                            <Summary
                                label="Linhas"
                                value={importRecord.total_rows}
                            />
                            <Summary
                                label="Válidas"
                                value={importRecord.valid_rows}
                            />
                            <Summary
                                label="Inválidas"
                                value={importRecord.invalid_rows}
                            />
                            <Summary
                                label="Duplicadas"
                                value={importRecord.duplicate_rows}
                            />
                        </div>
                        {importRecord.validation_errors.length > 0 && (
                            <div className="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                                <h3 className="font-medium">
                                    Linhas que serão ignoradas
                                </h3>
                                <ul className="mt-2 grid gap-1 text-sm text-muted-foreground">
                                    {importRecord.validation_errors.map(
                                        (error, index) => (
                                            <li key={`${error.line}-${index}`}>
                                                Linha {error.line}:{' '}
                                                {error.message}
                                            </li>
                                        ),
                                    )}
                                </ul>
                                {importRecord.total_rows >
                                    importRecord.validation_errors.length && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        A tela lista no máximo 100 ocorrências.
                                    </p>
                                )}
                            </div>
                        )}
                        {actionErrors.length > 0 && (
                            <div
                                role="alert"
                                className="mt-4 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                            >
                                {actionErrors.map((message, index) => (
                                    <p key={`${message}-${index}`}>{message}</p>
                                ))}
                            </div>
                        )}
                        <div className="mt-5 flex flex-wrap gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={validateRows}
                                disabled={actionForm.processing}
                            >
                                {importRecord.status === 'validated'
                                    ? 'Validar novamente'
                                    : 'Validar todas as linhas'}
                            </Button>
                            {importRecord.status === 'validated' && (
                                <Button
                                    type="button"
                                    onClick={confirmRows}
                                    disabled={
                                        importRecord.valid_rows === 0 ||
                                        actionForm.processing
                                    }
                                >
                                    Confirmar importação
                                </Button>
                            )}
                        </div>
                    </section>
                ) : null}

                {importRecord.status === 'completed' && (
                    <section className="rounded-2xl border border-primary/20 bg-primary/5 p-5 md:p-7">
                        <h2 className="font-semibold">
                            Resultado da importação
                        </h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-4">
                            <Summary
                                label="Criadas"
                                value={importRecord.created_rows}
                            />
                            <Summary
                                label="Atualizadas"
                                value={importRecord.updated_rows}
                            />
                            <Summary
                                label="Duplicadas"
                                value={importRecord.duplicate_rows}
                            />
                            <Summary
                                label="Ignoradas"
                                value={importRecord.skipped_rows}
                            />
                        </div>
                    </section>
                )}
            </main>
        </>
    );
}

function Summary({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm shadow-black/[0.02]">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

ImportShow.layout = {
    breadcrumbs: [
        { title: 'Importar dados', href: importsIndex.url() },
        { title: 'Prévia', href: '/imports' },
    ] satisfies BreadcrumbItem[],
};
