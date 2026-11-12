<?php

namespace App\Domain\Imports;

use App\Models\Company;
use App\Models\DataImport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyImportService
{
    /**
     * @param  list<list<string>>  $rows
     * @return array{
     *     totalRows: int,
     *     validRows: list<array{line: int, attributes: array<string, mixed>}>,
     *     invalidRows: int,
     *     duplicateRows: int,
     *     errors: list<array{line: int, type: string, message: string}>
     * }
     */
    public function analyze(DataImport $batch, User $actor, array $rows, int $totalRows, bool $lockDestination = false): array
    {
        $this->assertDestinationStillAuthorized($batch, $actor, $lockDestination);
        $mapping = is_array($batch->mapping) ? $batch->mapping : [];
        $fields = app(CompanyImportFields::class)->options();
        $candidates = [];
        $invalidRows = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $attributes = $this->mappedAttributes($mapping, $fields, $row);

            $attributes['name'] = trim((string) ($attributes['name'] ?? ''));
            $attributes['relationship_status'] = ($attributes['relationship_status'] ?? null) ?: 'prospect';
            $state = $attributes['state'] ?? null;
            $attributes['state'] = is_string($state) ? mb_strtoupper($state) : null;

            $taxId = $attributes['tax_id'] ?? null;
            if (is_string($taxId)) {
                $attributes['tax_id'] = preg_replace('/\D+/', '', $taxId) ?? '';
            }

            $validator = Validator::make($attributes, [
                'name' => ['required', 'string', 'max:180'],
                'trade_name' => ['nullable', 'string', 'max:180'],
                'legal_name' => ['nullable', 'string', 'max:180'],
                'tax_id' => ['nullable', 'regex:/^\d{14}$/'],
                'segment' => ['nullable', 'string', 'max:100'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
                'city' => ['nullable', 'string', 'max:120'],
                'state' => ['nullable', 'size:2'],
                'address' => ['nullable', 'string', 'max:2000'],
                'relationship_status' => ['required', 'in:prospect,customer,inactive,partner'],
                'lead_source' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:10000'],
            ], [
                'name.required' => 'Nome da empresa é obrigatório.',
                'tax_id.regex' => 'CNPJ deve conter 14 dígitos válidos.',
                'email.email' => 'E-mail inválido.',
                'website.url' => 'O site deve incluir um endereço válido, como https://exemplo.com.',
                'state.size' => 'UF deve conter duas letras.',
            ]);

            $messages = $validator->errors()->all();

            $taxId = $attributes['tax_id'] ?? null;
            if (is_string($taxId) && ! $this->isValidCnpj($taxId)) {
                $messages[] = 'CNPJ inválido.';
            }

            if ($messages !== []) {
                $invalidRows++;
                $this->appendError($errors, $index + 2, 'invalid', implode(' ', array_unique($messages)));

                continue;
            }

            $attributes = array_filter($attributes, fn (mixed $value): bool => $value !== null);
            $candidates[] = [
                'line' => $index + 2,
                'attributes' => $attributes,
                'keys' => $this->identityKeys($attributes),
            ];
        }

        $existingKeys = $this->existingIdentityKeys($batch, $candidates);
        $seenKeys = [];
        $validRows = [];
        $duplicateRows = 0;

        foreach ($candidates as $candidate) {
            $isDuplicate = false;

            foreach ($candidate['keys'] as $key) {
                if (isset($existingKeys[$key]) || isset($seenKeys[$key])) {
                    $isDuplicate = true;
                }

                $seenKeys[$key] = true;
            }

            if ($isDuplicate) {
                $duplicateRows++;
                $this->appendError($errors, $candidate['line'], 'duplicate', 'Possível duplicidade identificada; a linha será ignorada.');

                continue;
            }

            $attributes = $candidate['attributes'];
            $attributes['team_id'] = $batch->team_id;

            if ($actor->roleIn($batch->organization_id) === 'salesperson') {
                $attributes['owner_user_id'] = $actor->id;
            }

            $validRows[] = [
                'line' => $candidate['line'],
                'attributes' => $attributes,
            ];
        }

        return [
            'totalRows' => $totalRows,
            'validRows' => $validRows,
            'invalidRows' => $invalidRows,
            'duplicateRows' => $duplicateRows,
            'errors' => $errors,
        ];
    }

    private function assertDestinationStillAuthorized(DataImport $batch, User $actor, bool $lockDestination): void
    {
        $role = $actor->roleIn($batch->organization_id);
        $teamId = $batch->team_id;

        if (! in_array($role, ['owner', 'admin', 'manager', 'salesperson'], true)) {
            throw new AuthorizationException;
        }

        if ($teamId !== null) {
            $team = Team::query()
                ->where('organization_id', $batch->organization_id)
                ->whereKey($teamId)
                ->when($lockDestination, fn ($query) => $query->lockForUpdate())
                ->first();

            if ($team === null || $team->status !== 'active') {
                throw ValidationException::withMessages(['team_id' => 'A equipe de destino não está mais ativa nesta organização.']);
            }
        }

        if ($role === 'manager' && ($teamId === null || ! $this->hasActiveTeamMembership($batch, $actor, 'manager', $lockDestination))) {
            throw ValidationException::withMessages(['team_id' => 'Você não administra mais a equipe de destino selecionada.']);
        }

        if ($role === 'salesperson' && $teamId !== null && ! $this->hasActiveTeamMembership($batch, $actor, 'salesperson', $lockDestination)) {
            throw ValidationException::withMessages(['team_id' => 'Você não faz mais parte da equipe de destino selecionada.']);
        }
    }

    private function hasActiveTeamMembership(DataImport $batch, User $actor, string $role, bool $lockDestination): bool
    {
        $membership = DB::table('team_memberships')
            ->where('organization_id', $batch->organization_id)
            ->where('team_id', $batch->team_id)
            ->where('user_id', $actor->id)
            ->where('role', $role)
            ->where('is_active', true)
            ->when($lockDestination, fn ($query) => $query->lockForUpdate())
            ->first();

        return $membership !== null;
    }

    /**
     * @param  array<string, array{label: string, required: bool}>  $fields
     * @param  array<string, int>  $mapping
     * @param  list<string>  $row
     * @return array<string, string|null>
     */
    private function mappedAttributes(array $mapping, array $fields, array $row): array
    {
        $attributes = [];

        foreach ($mapping as $field => $columnIndex) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $value = trim($row[$columnIndex] ?? '');
            $attributes[$field] = $value === '' ? null : $value;
        }

        return $attributes;
    }

    /**
     * @param  list<array{line: int, attributes: array<string, mixed>, keys: list<string>}>  $candidates
     * @return array<string, true>
     */
    private function existingIdentityKeys(DataImport $batch, array $candidates): array
    {
        $taxIds = [];
        $names = [];
        $lowerNames = [];

        foreach ($candidates as $candidate) {
            $attributes = $candidate['attributes'];

            if (isset($attributes['tax_id'])) {
                $taxIds[] = $attributes['tax_id'];
                $taxIds[] = $this->formatCnpj($attributes['tax_id']);
            }

            $names[] = $attributes['name'];
            $lowerNames[] = mb_strtolower($attributes['name']);
        }

        $companies = Company::query()
            ->where('organization_id', $batch->organization_id)
            ->where(function ($query) use ($taxIds, $names, $lowerNames): void {
                if ($taxIds !== []) {
                    $query->whereIn('tax_id', array_unique($taxIds));
                }

                if ($names !== []) {
                    $method = $taxIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('name', array_unique($names))
                        ->orWhereIn(DB::raw('LOWER(name)'), array_unique($lowerNames));
                }
            })
            ->get(['tax_id', 'name', 'city', 'state']);

        $keys = [];

        foreach ($companies as $company) {
            foreach ($this->identityKeys([
                'tax_id' => $company->tax_id ? preg_replace('/\D+/', '', $company->tax_id) : null,
                'name' => $company->name,
                'city' => $company->city,
                'state' => $company->state,
            ]) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function identityKeys(array $attributes): array
    {
        $keys = [];
        $taxId = (string) ($attributes['tax_id'] ?? '');

        if ($taxId !== '') {
            $keys[] = 'cnpj:'.$taxId;
        }

        $city = $this->normalize((string) ($attributes['city'] ?? ''));
        $state = mb_strtoupper(trim((string) ($attributes['state'] ?? '')));

        if ($city !== '' && $state !== '') {
            $keys[] = 'location:'.$this->normalize((string) ($attributes['name'] ?? '')).'|'.$city.'|'.$state;
        }

        return $keys;
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }

    private function isValidCnpj(string $digits): bool
    {
        if (preg_match('/^\d{14}$/', $digits) !== 1 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return false;
        }

        $firstWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $secondWeights = [6, ...$firstWeights];
        $base = substr($digits, 0, 12);
        $first = $this->cnpjDigit($base, $firstWeights);
        $second = $this->cnpjDigit($base.$first, $secondWeights);

        return substr($digits, 12, 2) === $first.$second;
    }

    /** @param list<int> $weights */
    private function cnpjDigit(string $digits, array $weights): string
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }

    private function formatCnpj(string $digits): string
    {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $digits;
    }

    /** @param list<array{line: int, type: string, message: string}> $errors */
    private function appendError(array &$errors, int $line, string $type, string $message): void
    {
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'type' => $type, 'message' => $message];
        }
    }
}
