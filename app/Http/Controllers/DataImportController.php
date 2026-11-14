<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Imports\CompanyImportFields;
use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\ContactImportFields;
use App\Domain\Imports\ContactImportService;
use App\Domain\Imports\DataImportProcessor;
use App\Domain\Imports\ImportFileException;
use App\Domain\Imports\SaleImportFields;
use App\Domain\Imports\SaleImportService;
use App\Domain\Imports\SpreadsheetPreviewReader;
use App\Domain\Journeys\JourneyService;
use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveDataImportMappingRequest;
use App\Http\Requests\SaveDataImportResolutionsRequest;
use App\Http\Requests\StoreDataImportRequest;
use App\Jobs\ProcessDataImportChunk;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataImport;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DataImportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', DataImport::class);

        $user = $request->user();
        $organizationId = app(TenantContext::class)->id();
        $role = $user->roleIn($organizationId);
        $canViewAll = in_array($role, ['owner', 'admin'], true);
        $imports = DataImport::query()
            ->with('creator:id,name')
            ->when(! $canViewAll, fn ($imports) => $imports->where('created_by', $user->id))
            ->latest()
            ->paginate(15);
        $hiddenDuplicateRows = $this->hiddenDuplicateRowsForImports($imports->getCollection(), $user, $canViewAll);

        return Inertia::render('imports/index', [
            'imports' => $imports
                ->through(function (DataImport $dataImport) use ($hiddenDuplicateRows): array {
                    $hiddenRows = $hiddenDuplicateRows[$dataImport->id] ?? 0;

                    return [
                        'id' => $dataImport->id,
                        'record_type' => $dataImport->record_type,
                        'status' => $dataImport->status,
                        'original_filename' => $dataImport->original_filename,
                        'journey_mode' => $dataImport->journey_mode,
                        'total_rows' => $dataImport->total_rows,
                        'valid_rows' => $dataImport->valid_rows,
                        'processed_rows' => $dataImport->processed_rows,
                        'invalid_rows' => $dataImport->invalid_rows + $hiddenRows,
                        'created_rows' => $dataImport->created_rows,
                        'updated_rows' => $dataImport->updated_rows,
                        'skipped_rows' => $dataImport->skipped_rows,
                        'duplicate_rows' => max(0, $dataImport->duplicate_rows - $hiddenRows),
                        'expires_at' => $dataImport->expires_at->toIso8601String(),
                        'creator' => $dataImport->creator?->only(['id', 'name']),
                    ];
                }),
            'role' => $role,
            'teams' => Team::query()
                ->where('status', 'active')
                ->when($role === 'manager', fn ($teams) => $teams->whereIn(
                    'id',
                    app(CommercialAccessScope::class)->managedTeamIds($user, $organizationId),
                ))
                ->when($role === 'salesperson', fn ($teams) => $teams->whereHas('users', fn ($members) => $members
                    ->where('users.id', $user->id)
                    ->where('team_memberships.role', 'salesperson')
                    ->where('team_memberships.is_active', true)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'journeyTemplates' => JourneyTemplate::query()
                ->where('is_active', true)
                ->where('type', 'prospecting')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(StoreDataImportRequest $request): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 403);

        $attributes = $request->validated();
        $file = $request->file('file');
        $path = $file->store('imports/'.$organization->id, 'local');

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível armazenar o arquivo com segurança.']);
        }

        try {
            DB::transaction(function () use ($request, $attributes, $file, $path): void {
                $originalFilename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $originalFilename = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalFilename) ?? 'arquivo';

                DataImport::create([
                    'created_by' => $request->user()->id,
                    'team_id' => $attributes['team_id'] ?? null,
                    'record_type' => $attributes['record_type'],
                    'status' => 'uploaded',
                    'original_filename' => mb_substr($originalFilename, 0, 255),
                    'disk' => 'local',
                    'file_path' => $path,
                    'journey_mode' => $attributes['journey_mode'],
                    'journey_template_id' => $attributes['journey_template_id'] ?? null,
                    'expires_at' => now()->addHours(24),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return redirect()->route('imports.index')->with('success', 'Arquivo recebido. Revise os dados antes de confirmar a importação.');
    }

    public function show(Request $request, int $dataImport, SpreadsheetPreviewReader $previewReader): Response
    {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('view', $record);

        $actor = $request->user();
        $organizationId = app(TenantContext::class)->id();
        $isOrganizationAdministrator = in_array($actor->roleIn($organizationId), ['owner', 'admin'], true);
        $validationErrors = $this->visibleValidationErrors($record, $actor);
        $duplicateResolutions = $this->visibleDuplicateResolutions($record, $validationErrors);
        $hiddenDuplicateRows = $this->hiddenDuplicateRows($record, $actor, $isOrganizationAdministrator, $validationErrors);

        $expectedPathPrefix = 'imports/'.$organizationId.'/';

        $preview = null;
        $fileError = null;

        if (! in_array($record->status, ['queued', 'processing', 'failed', 'expired'], true)) {
            if ($record->disk !== 'local' || ! Str::startsWith($record->file_path, $expectedPathPrefix)) {
                abort(404);
            }

            try {
                $preview = $this->previewRecord($record, $previewReader);
            } catch (ImportFileException $exception) {
                $fileError = $exception->getMessage();
            }
        }

        return Inertia::render('imports/show', [
            'import' => [
                'id' => $record->id,
                'record_type' => $record->record_type,
                'status' => $record->status,
                'original_filename' => $record->original_filename,
                'journey_mode' => $record->journey_mode,
                'journey_template' => $record->journeyTemplate?->only(['id', 'name']),
                'mapping' => $record->mapping,
                'validation_errors' => $validationErrors,
                'duplicate_resolutions' => $duplicateResolutions,
                'total_rows' => $record->total_rows,
                'valid_rows' => $record->valid_rows,
                'processed_rows' => $record->processed_rows,
                'last_error' => $record->last_error,
                'invalid_rows' => $record->invalid_rows + $hiddenDuplicateRows,
                'duplicate_rows' => max(0, $record->duplicate_rows - $hiddenDuplicateRows),
                'created_rows' => $record->created_rows,
                'updated_rows' => $record->updated_rows,
                'skipped_rows' => $record->skipped_rows,
                'team' => $record->team?->only(['id', 'name']),
                'expires_at' => $record->expires_at->toIso8601String(),
            ],
            'preview' => $preview,
            'fileError' => $fileError,
            'fields' => $this->fieldsFor($record),
            'journeyTemplates' => $record->record_type === 'companies'
                ? JourneyTemplate::query()
                    ->where('is_active', true)
                    ->where('type', 'prospecting')
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_default'])
                : [],
        ]);
    }

    public function saveMapping(
        SaveDataImportMappingRequest $request,
        int $dataImport,
        SpreadsheetPreviewReader $previewReader,
    ): RedirectResponse {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('update', $record);
        if (in_array($record->status, ['completed', 'expired', 'queued', 'processing'], true)) {
            throw ValidationException::withMessages(['mapping' => 'Este lote não pode mais ser alterado.']);
        }

        $this->preventResettingPartialImport($record, 'mapping');

        $preview = $this->previewRecord($record, $previewReader);
        $validated = $request->validated();
        $mapping = array_filter(
            $validated['mapping'],
            fn (mixed $columnIndex): bool => $columnIndex !== null && $columnIndex !== '',
        );
        $fieldOptions = $this->fieldsFor($record);
        $allowedColumns = array_keys($preview['headers']);
        $errors = [];

        foreach ($mapping as $field => $columnIndex) {
            if (! array_key_exists($field, $fieldOptions)) {
                $errors['mapping'] = 'O mapeamento contém um campo que não pode ser importado.';

                continue;
            }

            if (! in_array((int) $columnIndex, $allowedColumns, true)) {
                $errors['mapping.'.$field] = 'Selecione uma coluna existente na planilha.';
            }
        }

        $normalizedMapping = array_map('intval', $mapping);
        $duplicateColumns = array_keys(array_filter(array_count_values($normalizedMapping), fn (int $count): bool => $count > 1));

        foreach ($normalizedMapping as $field => $columnIndex) {
            if (in_array($columnIndex, $duplicateColumns, true)) {
                $errors['mapping.'.$field] = 'Cada coluna pode ser associada a apenas um campo.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $attributes = [
            'mapping' => $normalizedMapping,
            'status' => 'mapped',
            'validation_errors' => null,
            'duplicate_resolutions' => null,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'processed_rows' => 0,
            'processing_attempts' => 0,
            'queue_dispatches' => 0,
            'last_processed_line' => null,
            'last_error' => null,
            'processing_started_at' => null,
            'processing_heartbeat_at' => null,
            'failed_at' => null,
        ];

        if ($record->record_type === 'companies' && array_key_exists('journey_mode', $validated)) {
            $attributes['journey_mode'] = $validated['journey_mode'];
            $attributes['journey_template_id'] = $validated['journey_mode'] === 'template'
                ? ($validated['journey_template_id'] ?? null)
                : null;
        }

        DB::transaction(function () use ($request, $dataImport, $attributes): void {
            $lockedRecord = DataImport::query()->lockForUpdate()->findOrFail($dataImport);
            Gate::forUser($request->user())->authorize('update', $lockedRecord);

            if (in_array($lockedRecord->status, ['completed', 'expired', 'queued', 'processing'], true)) {
                throw ValidationException::withMessages(['mapping' => 'Este lote já foi confirmado e não pode mais ser alterado.']);
            }

            $this->preventResettingPartialImport($lockedRecord, 'mapping');

            $lockedRecord->rows()->delete();
            $lockedRecord->update($attributes);
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Mapeamento salvo para este lote.');
    }

    public function saveResolutions(SaveDataImportResolutionsRequest $request, int $dataImport): RedirectResponse
    {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('update', $record);
        $requestedResolutions = $request->validated('resolutions');

        DB::transaction(function () use ($request, $record, $requestedResolutions): void {
            $batch = DataImport::query()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($request->user())->authorize('update', $batch);

            if ($batch->status !== 'validated' || ! in_array($batch->record_type, ['companies', 'contacts', 'sales'], true)) {
                throw ValidationException::withMessages(['resolutions' => 'As decisões de duplicidade não podem mais ser alteradas neste lote.']);
            }

            $duplicateErrors = collect($this->visibleValidationErrors($batch, $request->user()))
                ->filter(fn (array $error): bool => $error['type'] === 'duplicate')
                ->keyBy(fn (array $error): string => (string) $error['line']);
            $resolutions = [];

            foreach ($requestedResolutions as $line => $resolution) {
                $error = $duplicateErrors->get((string) $line);

                if ($error === null) {
                    $storedResolution = ($batch->duplicate_resolutions ?? [])[$line]
                        ?? ($batch->duplicate_resolutions ?? [])[(string) $line]
                        ?? null;
                    $storedAction = is_array($storedResolution) ? $storedResolution['action'] : null;
                    $storedTargetId = is_array($storedResolution) && is_int($storedResolution['target_id'] ?? null)
                        ? $storedResolution['target_id']
                        : null;
                    $requestedTargetId = isset($resolution['target_id']) ? (int) $resolution['target_id'] : null;
                    $sameSavedDecision = $storedAction === $resolution['action']
                        && ($resolution['action'] === 'update' || $storedTargetId === $requestedTargetId);

                    if ($sameSavedDecision) {
                        continue;
                    }

                    throw ValidationException::withMessages(['resolutions' => 'Uma das linhas selecionadas não está mais marcada como duplicada. Valide o arquivo novamente.']);
                }

                $action = $resolution['action'];
                $candidates = is_array($error['candidates'] ?? null) ? $error['candidates'] : [];
                $targetId = isset($resolution['target_id']) ? (int) $resolution['target_id'] : null;

                if ($action === 'create' && ($error['can_create'] ?? false) !== true) {
                    throw ValidationException::withMessages(['resolutions' => 'Esta linha possui um identificador único já utilizado e não pode criar outro registro.']);
                }

                if (in_array($action, ['update', 'resolve'], true) && ($error['can_update'] ?? true) !== true) {
                    throw ValidationException::withMessages(['resolutions' => 'Você não tem autorização para atualizar esta venda. Ignore a linha.']);
                }

                if ($action === 'update') {
                    if (count($candidates) !== 1) {
                        throw ValidationException::withMessages(['resolutions' => 'Atualização direta exige exatamente um registro correspondente. Escolha a resolução manual.']);
                    }

                    $targetId = (int) $candidates[0]['id'];
                }

                if ($action === 'resolve' && ! in_array($targetId, array_map(
                    static fn (array $candidate): int => (int) $candidate['id'],
                    $candidates,
                ), true)) {
                    throw ValidationException::withMessages(['resolutions' => 'Selecione um registro correspondente visível para resolver a ambiguidade.']);
                }

                $resolutions[(string) $line] = [
                    'action' => $action,
                    ...(in_array($action, ['update', 'resolve'], true) && $targetId !== null ? ['target_id' => $targetId] : []),
                ];
            }

            $batch->rows()->delete();
            $batch->update([
                'status' => 'mapped',
                'duplicate_resolutions' => array_replace($batch->duplicate_resolutions ?? [], $resolutions),
                'valid_rows' => 0,
                'duplicate_rows' => 0,
                'skipped_rows' => 0,
            ]);
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Decisões salvas. Valide novamente para revisar o resultado antes de confirmar.');
    }

    public function validateImport(
        Request $request,
        int $dataImport,
        SpreadsheetPreviewReader $previewReader,
        CompanyImportService $companyImports,
        ContactImportService $contactImports,
        SaleImportService $saleImports,
    ): RedirectResponse {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('update', $record);

        if ($record->mapping === null || $record->mapping === []) {
            throw ValidationException::withMessages(['mapping' => 'Salve o mapeamento antes de validar as linhas.']);
        }

        if (in_array($record->status, ['completed', 'expired', 'queued', 'processing'], true)) {
            throw ValidationException::withMessages(['file' => 'Este lote não pode mais ser validado.']);
        }

        $this->preventResettingPartialImport($record, 'file');

        try {
            $preview = $this->previewRecord($record, $previewReader, SpreadsheetPreviewReader::MAX_ROWS);
        } catch (ImportFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $importService = match ($record->record_type) {
            'companies' => $companyImports,
            'contacts' => $contactImports,
            'sales' => $saleImports,
            default => abort(422),
        };

        $analyzedMapping = $record->mapping;
        $analyzedDuplicateResolutions = $record->duplicate_resolutions;
        $analysis = $importService->analyze(
            $record,
            $request->user(),
            $preview['rows'],
            $preview['totalRows'],
            false,
            $preview['malformedRows'],
        );

        DB::transaction(function () use ($request, $record, $analysis, $analyzedMapping, $analyzedDuplicateResolutions): void {
            $batch = DataImport::query()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($request->user())->authorize('update', $batch);

            if (in_array($batch->status, ['completed', 'expired', 'queued', 'processing'], true)) {
                throw ValidationException::withMessages(['file' => 'Este lote não pode mais ser validado.']);
            }

            if ($batch->mapping !== $analyzedMapping || $batch->duplicate_resolutions !== $analyzedDuplicateResolutions) {
                throw ValidationException::withMessages(['file' => 'O mapeamento ou as decisões de duplicidade mudaram durante a validação. Valide o lote novamente.']);
            }

            $this->preventResettingPartialImport($batch, 'file');

            $validRows = $analysis['totalRows'] > DataImportProcessor::ASYNC_ROW_THRESHOLD
                ? $analysis['validRows']
                : [];
            app(DataImportProcessor::class)->stageRows($batch, $validRows);

            $batch->update([
                'status' => 'validated',
                'total_rows' => $analysis['totalRows'],
                'valid_rows' => count($analysis['validRows']),
                'processed_rows' => 0,
                'processing_attempts' => 0,
                'queue_dispatches' => 0,
                'last_processed_line' => null,
                'last_error' => null,
                'processing_started_at' => null,
                'processing_heartbeat_at' => null,
                'failed_at' => null,
                'invalid_rows' => $analysis['invalidRows'],
                'duplicate_rows' => $analysis['duplicateRows'],
                'skipped_rows' => $analysis['invalidRows'] + $analysis['duplicateRows'],
                'validation_errors' => $analysis['errors'],
            ]);
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Validação concluída. Revise os totais antes de confirmar.');
    }

    public function confirm(
        Request $request,
        int $dataImport,
        SpreadsheetPreviewReader $previewReader,
        CompanyImportService $companyImports,
        ContactImportService $contactImports,
        SaleImportService $saleImports,
        CustomerScoreService $scores,
        CommercialInactivityService $inactivity,
        JourneyService $journeys,
    ): RedirectResponse {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('update', $record);

        DB::transaction(function () use ($request, $record, $previewReader, $companyImports, $contactImports, $saleImports, $scores, $inactivity, $journeys): void {
            $batch = DataImport::query()->lockForUpdate()->findOrFail($record->id);
            Gate::authorize('update', $batch);

            if (in_array($batch->status, ['completed', 'queued', 'processing'], true)) {
                return;
            }

            if ($batch->status !== 'validated') {
                throw ValidationException::withMessages(['file' => 'Valide o lote antes de confirmar a importação.']);
            }

            if ($batch->mapping === null || $batch->mapping === []) {
                throw ValidationException::withMessages(['mapping' => 'Salve o mapeamento antes de confirmar a importação.']);
            }

            $organization = Organization::query()->lockForUpdate()->findOrFail($batch->organization_id);
            Gate::authorize('update', $batch);

            $journeyTemplate = null;

            if ($batch->record_type === 'companies') {
                $journeyTemplate = match ($batch->journey_mode) {
                    'none' => null,
                    'default' => $journeys->ensureDefaultTemplate($organization),
                    'template' => JourneyTemplate::query()
                        ->where('organization_id', $batch->organization_id)
                        ->where('is_active', true)
                        ->where('type', 'prospecting')
                        ->lockForUpdate()
                        ->find($batch->journey_template_id),
                    default => throw ValidationException::withMessages([
                        'journey_mode' => 'Escolha uma opção válida para a jornada.',
                    ]),
                };

                if ($batch->journey_mode === 'template'
                    && $journeyTemplate === null) {
                    throw ValidationException::withMessages([
                        'journey_template_id' => 'O modelo selecionado foi desativado ou não está mais disponível. Escolha outro modelo ou nenhuma jornada, salve o mapeamento e tente novamente.',
                    ]);
                }
            }

            if ($batch->total_rows > DataImportProcessor::ASYNC_ROW_THRESHOLD) {
                if ($batch->valid_rows === 0) {
                    $batch->update([
                        'status' => 'completed',
                        'processed_rows' => $batch->total_rows,
                        'confirmed_at' => now(),
                    ]);

                    return;
                }

                if (config('queue.default') === 'sync') {
                    throw ValidationException::withMessages([
                        'file' => 'Este lote exige uma fila assíncrona. Configure Redis e um worker antes de confirmar arquivos com mais de 250 linhas.',
                    ]);
                }

                $pendingRows = $batch->rows()->where('status', 'pending')->count();

                if ($pendingRows !== $batch->valid_rows) {
                    throw ValidationException::withMessages(['file' => 'O preparo seguro das linhas foi interrompido. Valide o arquivo novamente.']);
                }

                $attemptNumber = $batch->processing_attempts + 1;
                $batch->update([
                    'status' => 'queued',
                    'processed_rows' => $batch->total_rows - $batch->valid_rows,
                    'processing_attempts' => $attemptNumber,
                    'queue_dispatches' => 1,
                    'last_processed_line' => null,
                    'last_error' => null,
                    'processing_started_at' => null,
                    'processing_heartbeat_at' => null,
                    'failed_at' => null,
                ]);

                ProcessDataImportChunk::dispatch($batch->organization_id, $batch->id, $attemptNumber)->afterCommit();

                return;
            }

            try {
                $preview = $this->previewRecord($batch, $previewReader, SpreadsheetPreviewReader::MAX_ROWS);
            } catch (ImportFileException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }

            $createdRows = 0;
            $updatedRows = 0;
            $raceDuplicates = 0;
            $raceInvalidRows = 0;

            if ($batch->record_type === 'contacts') {
                $analysis = $contactImports->analyze(
                    $batch,
                    $request->user(),
                    $preview['rows'],
                    $preview['totalRows'],
                    true,
                    $preview['malformedRows'],
                );
                $importResult = $contactImports->import($batch, $request->user(), $analysis['validRows']);
                $createdRows = $importResult['createdRows'];
                $updatedRows = $importResult['updatedRows'];
                $raceDuplicates = $importResult['duplicateRows'];
                $raceInvalidRows = $importResult['invalidRows'];
                $analysis['errors'] = array_merge($analysis['errors'], $importResult['errors']);
            } elseif ($batch->record_type === 'sales') {
                $analysis = $saleImports->analyze(
                    $batch,
                    $request->user(),
                    $preview['rows'],
                    $preview['totalRows'],
                    true,
                    $preview['malformedRows'],
                );
                $importResult = $saleImports->import($batch, $request->user(), $analysis['validRows'], $scores, $inactivity, $journeys);
                $createdRows = $importResult['createdRows'];
                $updatedRows = $importResult['updatedRows'];
                $raceDuplicates = $importResult['duplicateRows'];
                $raceInvalidRows = $importResult['invalidRows'];
                $analysis['errors'] = array_merge($analysis['errors'], $importResult['errors']);
            } elseif ($batch->record_type !== 'companies') {
                abort(422);
            } else {
                $analysis = $companyImports->analyze(
                    $batch,
                    $request->user(),
                    $preview['rows'],
                    $preview['totalRows'],
                    true,
                    $preview['malformedRows'],
                );

                $importResult = $companyImports->import(
                    $batch,
                    $request->user(),
                    $analysis['validRows'],
                    $scores,
                    $journeyTemplate,
                    $journeys,
                );
                $createdRows = $importResult['createdRows'];
                $updatedRows = $importResult['updatedRows'];
                $raceDuplicates = $importResult['duplicateRows'];
                $raceInvalidRows = $importResult['invalidRows'];
                $analysis['errors'] = array_merge($analysis['errors'], $importResult['errors']);
            }

            $duplicateRows = $analysis['duplicateRows'] + $raceDuplicates;
            $invalidRows = $analysis['invalidRows'] + $raceInvalidRows;
            $batch->update([
                'status' => 'completed',
                'total_rows' => $analysis['totalRows'],
                'valid_rows' => count($analysis['validRows']) - $raceDuplicates - $raceInvalidRows,
                'processed_rows' => $analysis['totalRows'],
                'invalid_rows' => $invalidRows,
                'duplicate_rows' => $duplicateRows,
                'created_rows' => $createdRows,
                'updated_rows' => $updatedRows,
                'skipped_rows' => $invalidRows + $duplicateRows,
                'validation_errors' => $analysis['errors'],
                'last_error' => null,
                'processing_started_at' => null,
                'processing_heartbeat_at' => null,
                'failed_at' => null,
                'confirmed_at' => now(),
            ]);
        });

        $latestRecord = DataImport::query()->findOrFail($record->id);
        $message = in_array($latestRecord->status, ['queued', 'processing'], true)
            ? 'Importação enviada à fila. Você pode acompanhar o progresso nesta página.'
            : 'Importação concluída. Confira os totais e as linhas ignoradas.';

        return redirect()->route('imports.show', $record->id)->with('success', $message);
    }

    public function resume(Request $request, int $dataImport): RedirectResponse
    {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('update', $record);

        DB::transaction(function () use ($request, $record): void {
            $batch = DataImport::query()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($request->user())->authorize('update', $batch);

            if ($batch->status !== 'failed') {
                throw ValidationException::withMessages(['file' => 'Somente lotes interrompidos podem ser retomados.']);
            }

            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['file' => 'O prazo para retomar este arquivo terminou. Envie-o novamente.']);
            }

            if (config('queue.default') === 'sync') {
                throw ValidationException::withMessages([
                    'file' => 'Configure Redis e um worker antes de retomar este lote para evitar processá-lo dentro da requisição.',
                ]);
            }

            $pendingRows = $batch->rows()->where('status', 'pending')->count();
            $expectedPendingRows = max(0, $batch->total_rows - $batch->processed_rows);

            if ($pendingRows === 0 || $pendingRows !== $expectedPendingRows) {
                throw ValidationException::withMessages(['file' => 'As linhas restantes não estão disponíveis para retomada. Valide o arquivo novamente.']);
            }

            $attemptNumber = $batch->processing_attempts + 1;
            $batch->update([
                'status' => 'queued',
                'processing_attempts' => $attemptNumber,
                'queue_dispatches' => 1,
                'last_error' => null,
                'processing_started_at' => null,
                'processing_heartbeat_at' => null,
                'failed_at' => null,
            ]);

            ProcessDataImportChunk::dispatch($batch->organization_id, $batch->id, $attemptNumber)->afterCommit();
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Importação retomada. O processamento continuará a partir das linhas pendentes.');
    }

    /** @return array{sheetName: string, headers: list<string>, rows: list<list<string>>, totalRows: int, malformedRows: list<int>} */
    private function previewRecord(DataImport $record, SpreadsheetPreviewReader $previewReader, int $sampleLimit = 10): array
    {
        if ($record->expires_at->isPast()) {
            throw new ImportFileException('O arquivo temporário expirou e não pode mais ser pré-visualizado.');
        }

        $expectedPathPrefix = 'imports/'.app(TenantContext::class)->id().'/';

        if ($record->disk !== 'local' || ! Str::startsWith($record->file_path, $expectedPathPrefix)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($record->file_path)) {
            throw new ImportFileException('O arquivo temporário não está mais disponível. Envie-o novamente para continuar.');
        }

        $format = strtolower(pathinfo($record->original_filename, PATHINFO_EXTENSION));

        return $previewReader->preview(
            Storage::disk('local')->path($record->file_path),
            $format,
            $sampleLimit,
        );
    }

    /** @return array<string, array{label: string, required: bool}> */
    private function fieldsFor(DataImport $record): array
    {
        return match ($record->record_type) {
            'companies' => app(CompanyImportFields::class)->options(),
            'contacts' => app(ContactImportFields::class)->options(),
            'sales' => app(SaleImportFields::class)->options(),
            default => [],
        };
    }

    private function preventResettingPartialImport(DataImport $batch, string $field): void
    {
        if ($batch->status === 'failed' && $batch->created_rows > 0) {
            throw ValidationException::withMessages([
                $field => 'Este lote já gravou registros. Retome as linhas pendentes para evitar repetir dados já importados.',
            ]);
        }
    }

    /** @return list<array{line: int, type: string, message: string, candidates?: list<array<string, mixed>>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database', lines?: list<int>}> */
    private function visibleValidationErrors(DataImport $record, User $actor): array
    {
        $errors = $record->validation_errors ?? [];

        if (! in_array($record->record_type, ['companies', 'contacts', 'sales'], true)) {
            return $errors;
        }

        $candidateIds = collect($errors)
            ->flatMap(fn (array $error): array => is_array($error['candidates'] ?? null)
                ? array_map(static fn (array $candidate): int => (int) $candidate['id'], $error['candidates'])
                : [])
            ->unique()
            ->values()
            ->all();
        if ($record->record_type === 'contacts') {
            $visibleContacts = Contact::query()
                ->where('organization_id', $record->organization_id)
                ->visibleTo($actor)
                ->whereKey($candidateIds)
                ->get(['id', 'name', 'email', 'phone'])
                ->keyBy('id');

            return array_map(function (array $error) use ($visibleContacts): array {
                if ($error['type'] !== 'duplicate') {
                    return $error;
                }

                $candidates = array_values(array_filter(array_map(function (array $candidate) use ($visibleContacts): ?array {
                    $contact = $visibleContacts->get((int) $candidate['id']);

                    if (! $contact instanceof Contact) {
                        return null;
                    }

                    return [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'email_masked' => $this->maskEmail($contact->email),
                        'phone_masked' => $this->maskPhone($contact->phone),
                    ];
                }, $error['candidates'] ?? []), static fn (?array $candidate): bool => $candidate !== null));

                if ($candidates === []) {
                    return $this->inaccessibleDuplicateError($error);
                }

                $error['candidates'] = $candidates;

                return $error;
            }, $errors);
        }

        if ($record->record_type === 'sales') {
            $visibleSales = Sale::query()
                ->where('organization_id', $record->organization_id)
                ->visibleTo($actor)
                ->whereKey($candidateIds)
                ->with('company:id,name')
                ->get(['id', 'company_id', 'description', 'amount', 'sold_at', 'status'])
                ->keyBy('id');

            return array_map(function (array $error) use ($visibleSales): array {
                if ($error['type'] !== 'duplicate') {
                    return $error;
                }

                $candidates = array_values(array_filter(array_map(function (array $candidate) use ($visibleSales): ?array {
                    $sale = $visibleSales->get((int) $candidate['id']);

                    if (! $sale instanceof Sale) {
                        return null;
                    }

                    $company = $sale->company;

                    return [
                        'id' => $sale->id,
                        'name' => $company->name,
                        'detail' => trim($sale->description).' · R$ '.number_format((float) $sale->amount, 2, ',', '.')
                            .' · '.$sale->sold_at->format('Y-m-d').' · '.($sale->status === 'confirmed' ? 'confirmada' : 'cancelada'),
                    ];
                }, $error['candidates'] ?? []), static fn (?array $candidate): bool => $candidate !== null));

                if ($candidates === []) {
                    if (($error['duplicate_source'] ?? null) === 'file') {
                        return $error;
                    }

                    return $this->inaccessibleDuplicateError($error);
                }

                $error['candidates'] = $candidates;

                return $error;
            }, $errors);
        }

        $visibleCompanies = Company::query()
            ->where('organization_id', $record->organization_id)
            ->visibleTo($actor)
            ->whereKey($candidateIds)
            ->get(['id', 'name', 'city', 'state', 'tax_id'])
            ->keyBy('id');

        return array_map(function (array $error) use ($visibleCompanies): array {
            if ($error['type'] !== 'duplicate') {
                return $error;
            }

            $visibleCandidates = [];

            foreach ($error['candidates'] ?? [] as $candidate) {
                $company = $visibleCompanies->get($candidate['id']);

                if ($company instanceof Company) {
                    $visibleCandidates[] = [
                        'id' => $company->id,
                        'name' => $company->name,
                        'city' => $company->city,
                        'state' => $company->state,
                        'tax_id_masked' => $this->maskTaxId($company->tax_id),
                    ];
                }
            }

            if ($visibleCandidates === []) {
                return $this->inaccessibleDuplicateError($error);
            }

            $error['candidates'] = $visibleCandidates;

            return $error;
        }, $errors);
    }

    /**
     * @param  list<array{line: int, type: string, message: string, candidates?: list<array<string, mixed>>, can_create?: bool, can_update?: bool, duplicate_source?: 'file'|'database', lines?: list<int>}>  $visibleErrors
     */
    private function hiddenDuplicateRows(
        DataImport $record,
        User $actor,
        bool $isOrganizationAdministrator,
        array $visibleErrors,
    ): int {
        if (! in_array($record->record_type, ['companies', 'contacts', 'sales'], true)) {
            return 0;
        }

        $visibleErrorsByLine = collect($visibleErrors)->keyBy('line');
        $duplicateErrors = collect($record->validation_errors ?? [])
            ->filter(fn (array $error): bool => $error['type'] === 'duplicate');
        $representedDuplicateRows = $duplicateErrors
            ->sum(fn (array $error): int => count($error['lines'] ?? [$error['line']]));
        $hiddenDuplicateRows = $duplicateErrors
            ->sum(function (array $error) use ($visibleErrorsByLine): int {
                $visibleError = $visibleErrorsByLine->get($error['line']);

                if (($visibleError['type'] ?? null) !== 'invalid') {
                    return 0;
                }

                return count($error['lines'] ?? [$error['line']]);
            });

        return $hiddenDuplicateRows + $this->unrepresentedDuplicateRows($record, $isOrganizationAdministrator, $representedDuplicateRows);
    }

    /**
     * @param  EloquentCollection<int, DataImport>  $imports
     * @return array<int, int>
     */
    private function hiddenDuplicateRowsForImports(
        EloquentCollection $imports,
        User $actor,
        bool $isOrganizationAdministrator,
    ): array {
        $candidateIds = ['companies' => [], 'contacts' => [], 'sales' => []];

        foreach ($imports as $record) {
            if (! array_key_exists($record->record_type, $candidateIds)) {
                continue;
            }

            foreach ($record->validation_errors ?? [] as $error) {
                if ($error['type'] !== 'duplicate') {
                    continue;
                }

                foreach ($error['candidates'] ?? [] as $candidate) {
                    $candidateIds[$record->record_type][] = (int) $candidate['id'];
                }
            }
        }

        $organizationId = app(TenantContext::class)->id();
        $visibleIds = [
            'companies' => $candidateIds['companies'] === []
                ? []
                : Company::query()
                    ->where('organization_id', $organizationId)
                    ->visibleTo($actor)
                    ->whereKey(array_unique($candidateIds['companies']))
                    ->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->all(),
            'contacts' => $candidateIds['contacts'] === []
                ? []
                : Contact::query()
                    ->where('organization_id', $organizationId)
                    ->visibleTo($actor)
                    ->whereKey(array_unique($candidateIds['contacts']))
                    ->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->all(),
            'sales' => $candidateIds['sales'] === []
                ? []
                : Sale::query()
                    ->where('organization_id', $organizationId)
                    ->visibleTo($actor)
                    ->whereKey(array_unique($candidateIds['sales']))
                    ->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->all(),
        ];
        $visibleLookup = [
            'companies' => array_fill_keys($visibleIds['companies'], true),
            'contacts' => array_fill_keys($visibleIds['contacts'], true),
            'sales' => array_fill_keys($visibleIds['sales'], true),
        ];
        $hiddenRows = [];

        foreach ($imports as $record) {
            if (! array_key_exists($record->record_type, $visibleLookup)) {
                continue;
            }

            $visibleCandidates = $visibleLookup[$record->record_type];
            $duplicateErrors = collect($record->validation_errors ?? [])
                ->filter(fn (array $error): bool => $error['type'] === 'duplicate')
                ->values();
            $representedDuplicateRows = $duplicateErrors
                ->sum(fn (array $error): int => count($error['lines'] ?? [$error['line']]));
            $hiddenCount = $duplicateErrors
                ->sum(function (array $error) use ($visibleCandidates): int {
                    if (($error['duplicate_source'] ?? null) === 'file') {
                        return 0;
                    }

                    foreach ($error['candidates'] ?? [] as $candidate) {
                        if (isset($visibleCandidates[(int) $candidate['id']])) {
                            return 0;
                        }
                    }

                    return count($error['lines'] ?? [$error['line']]);
                }) + $this->unrepresentedDuplicateRows($record, $isOrganizationAdministrator, $representedDuplicateRows);

            if ($hiddenCount > 0) {
                $hiddenRows[$record->id] = (int) $hiddenCount;
            }
        }

        return $hiddenRows;
    }

    private function unrepresentedDuplicateRows(
        DataImport $record,
        bool $isOrganizationAdministrator,
        int $representedDuplicateRows,
    ): int {
        if ($isOrganizationAdministrator || count($record->validation_errors ?? []) < DataImport::MAX_VALIDATION_ERRORS) {
            return 0;
        }

        return max(0, $record->duplicate_rows - $representedDuplicateRows);
    }

    /** @param array<string, mixed> $error
     * @return array{line: int, type: string, message: string}
     */
    private function inaccessibleDuplicateError(array $error): array
    {
        return [
            'line' => $error['line'],
            'type' => 'invalid',
            'message' => 'A linha não pode ser importada no escopo de acesso atual.',
        ];
    }

    /**
     * @param  list<array{line: int, type: string, message: string, candidates?: list<array<string, mixed>>, can_create?: bool, lines?: list<int>}>  $validationErrors
     * @return array<int|string, array{action: string, target_id?: int}>
     */
    private function visibleDuplicateResolutions(DataImport $record, array $validationErrors): array
    {
        $storedResolutions = $record->duplicate_resolutions ?? [];
        $visibleResolutions = [];

        foreach ($validationErrors as $error) {
            if ($error['type'] !== 'duplicate') {
                continue;
            }

            $line = (string) $error['line'];
            $resolution = $storedResolutions[$error['line']] ?? null;

            if ($resolution === null) {
                continue;
            }

            $targetId = $resolution['target_id'] ?? null;
            $visibleCandidateIds = array_map(
                static fn (array $candidate): int => (int) $candidate['id'],
                $error['candidates'] ?? [],
            );

            if (is_int($targetId) && ! in_array($targetId, $visibleCandidateIds, true)) {
                continue;
            }

            $visibleResolutions[$line] = [
                'action' => $resolution['action'],
                ...(is_int($targetId) ? ['target_id' => $targetId] : []),
            ];
        }

        return $visibleResolutions;
    }

    private function maskTaxId(?string $taxId): ?string
    {
        $digits = preg_replace('/\D+/', '', $taxId ?? '') ?? '';

        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.***.***/****-'.substr($digits, -2);
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '') {
            return null;
        }

        return mb_substr($local, 0, 1).'***'.(mb_strlen($local) > 1 ? mb_substr($local, -1) : '').'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone ?? '') ?? '';

        return strlen($digits) < 4 ? null : '***'.substr($digits, -4);
    }
}
