<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialInactivityService;
use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Imports\CompanyImportFields;
use App\Domain\Imports\CompanyImportService;
use App\Domain\Imports\ContactImportFields;
use App\Domain\Imports\ContactImportService;
use App\Domain\Imports\ImportFileException;
use App\Domain\Imports\SaleImportFields;
use App\Domain\Imports\SaleImportService;
use App\Domain\Imports\SpreadsheetPreviewReader;
use App\Domain\Journeys\JourneyService;
use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveDataImportMappingRequest;
use App\Http\Requests\StoreDataImportRequest;
use App\Models\Company;
use App\Models\DataImport;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\Team;
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

        return Inertia::render('imports/index', [
            'imports' => DataImport::query()
                ->with('creator:id,name')
                ->when(! $canViewAll, fn ($imports) => $imports->where('created_by', $user->id))
                ->latest()
                ->paginate(15)
                ->through(fn (DataImport $dataImport): array => [
                    'id' => $dataImport->id,
                    'record_type' => $dataImport->record_type,
                    'status' => $dataImport->status,
                    'original_filename' => $dataImport->original_filename,
                    'journey_mode' => $dataImport->journey_mode,
                    'total_rows' => $dataImport->total_rows,
                    'valid_rows' => $dataImport->valid_rows,
                    'invalid_rows' => $dataImport->invalid_rows,
                    'created_rows' => $dataImport->created_rows,
                    'updated_rows' => $dataImport->updated_rows,
                    'skipped_rows' => $dataImport->skipped_rows,
                    'duplicate_rows' => $dataImport->duplicate_rows,
                    'expires_at' => $dataImport->expires_at->toIso8601String(),
                    'creator' => $dataImport->creator?->only(['id', 'name']),
                ]),
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

    public function show(int $dataImport, SpreadsheetPreviewReader $previewReader): Response
    {
        $record = DataImport::query()->findOrFail($dataImport);
        Gate::authorize('view', $record);

        $organizationId = app(TenantContext::class)->id();
        $expectedPathPrefix = 'imports/'.$organizationId.'/';

        if ($record->disk !== 'local' || ! Str::startsWith($record->file_path, $expectedPathPrefix)) {
            abort(404);
        }

        $preview = null;
        $fileError = null;

        try {
            $preview = $this->previewRecord($record, $previewReader);
        } catch (ImportFileException $exception) {
            $fileError = $exception->getMessage();
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
                'validation_errors' => $record->validation_errors ?? [],
                'total_rows' => $record->total_rows,
                'valid_rows' => $record->valid_rows,
                'invalid_rows' => $record->invalid_rows,
                'duplicate_rows' => $record->duplicate_rows,
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
        if (in_array($record->status, ['completed', 'expired'], true)) {
            throw ValidationException::withMessages(['mapping' => 'Este lote não pode mais ser alterado.']);
        }

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
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
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

            if (in_array($lockedRecord->status, ['completed', 'expired'], true)) {
                throw ValidationException::withMessages(['mapping' => 'Este lote já foi confirmado e não pode mais ser alterado.']);
            }

            $lockedRecord->update($attributes);
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Mapeamento salvo para este lote.');
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

        if (in_array($record->status, ['completed', 'expired'], true)) {
            throw ValidationException::withMessages(['file' => 'Este lote não pode mais ser validado.']);
        }

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

        $analysis = $importService->analyze(
            $record,
            $request->user(),
            $preview['rows'],
            $preview['totalRows'],
            false,
            $preview['malformedRows'],
        );

        $record->update([
            'status' => 'validated',
            'total_rows' => $analysis['totalRows'],
            'valid_rows' => count($analysis['validRows']),
            'invalid_rows' => $analysis['invalidRows'],
            'duplicate_rows' => $analysis['duplicateRows'],
            'validation_errors' => $analysis['errors'],
        ]);

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

            if ($batch->status === 'completed') {
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

            try {
                $preview = $this->previewRecord($batch, $previewReader, SpreadsheetPreviewReader::MAX_ROWS);
            } catch (ImportFileException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }

            $createdRows = 0;
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

                foreach ($analysis['validRows'] as $row) {
                    $attributes = $row['attributes'];
                    $taxId = $attributes['tax_id'] ?? null;
                    unset($attributes['tax_id']);

                    if ($taxId !== null) {
                        $company = Company::query()->firstOrCreate(
                            ['tax_id' => $taxId],
                            ['tax_id' => $taxId, ...$attributes],
                        );

                        if (! $company->wasRecentlyCreated) {
                            $canViewExistingCompany = Company::query()
                                ->where('organization_id', $batch->organization_id)
                                ->visibleTo($request->user())
                                ->whereKey($company->id)
                                ->exists();

                            if ($canViewExistingCompany) {
                                $raceDuplicates++;
                                $error = [
                                    'line' => $row['line'],
                                    'type' => 'duplicate',
                                    'message' => 'A empresa passou a existir durante a importação; a linha foi ignorada com segurança.',
                                ];
                            } else {
                                $raceInvalidRows++;
                                $error = [
                                    'line' => $row['line'],
                                    'type' => 'invalid',
                                    'message' => 'A linha não pode ser importada no escopo de acesso atual.',
                                ];
                            }

                            if (count($analysis['errors']) < 100) {
                                $analysis['errors'][] = $error;
                            }

                            continue;
                        }
                    } else {
                        $company = Company::query()->create($attributes);
                    }

                    $scores->recalculateForCompany($company, 'Importação de planilha');

                    if ($journeyTemplate !== null && $company->relationship_status === 'prospect') {
                        $journeys->enrollManually($company, $journeyTemplate, $request->user());
                    }

                    $createdRows++;
                }
            }

            $duplicateRows = $analysis['duplicateRows'] + $raceDuplicates;
            $invalidRows = $analysis['invalidRows'] + $raceInvalidRows;
            $batch->update([
                'status' => 'completed',
                'total_rows' => $analysis['totalRows'],
                'valid_rows' => count($analysis['validRows']) - $raceDuplicates - $raceInvalidRows,
                'invalid_rows' => $invalidRows,
                'duplicate_rows' => $duplicateRows,
                'created_rows' => $createdRows,
                'updated_rows' => 0,
                'skipped_rows' => $invalidRows + $duplicateRows,
                'validation_errors' => $analysis['errors'],
                'confirmed_at' => now(),
            ]);
        });

        return redirect()->route('imports.show', $record->id)->with('success', 'Importação concluída. Confira os totais e as linhas ignoradas.');
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
}
