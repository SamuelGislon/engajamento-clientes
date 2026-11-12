<?php

namespace App\Domain\Commercial;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\CustomerScore;
use App\Models\CustomerScoreHistory;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerScoreService
{
    public function recalculateForCompany(Company $company, string $reason): CustomerScore
    {
        return DB::transaction(function () use ($company, $reason): CustomerScore {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $organization = app(TenantContext::class)->organization();
            abort_if($organization === null, 404);
            $settings = app(CommercialSettingsService::class)->forOrganization($organization);
            $score = CustomerScore::query()
                ->where('company_id', $lockedCompany->id)
                ->lockForUpdate()
                ->first();
            $previous = $score === null ? null : $this->snapshot($score);

            $sales = Sale::query()
                ->where('company_id', $lockedCompany->id)
                ->where('status', 'confirmed')
                ->where('sold_at', '>=', CarbonImmutable::now('UTC')->subYear());
            $volume = $this->numericString((string) $sales->sum('amount'));
            $purchaseCount = (clone $sales)->count();
            $volumePoints = $this->volumePoints($volume, $settings->volume_score_bands);
            $frequencyPoints = $this->frequencyPoints($purchaseCount, $settings->frequency_score_bands);
            $potentialPoints = $score?->potential_points;
            $relationshipPoints = $score?->relationship_points;
            $isComplete = $potentialPoints !== null && $relationshipPoints !== null;
            $totalScore = $isComplete
                ? $volumePoints + $frequencyPoints + $potentialPoints + $relationshipPoints
                : null;
            $tier = $totalScore === null ? null : $this->tierForScore($totalScore, $settings->tiers);

            $score ??= new CustomerScore(['company_id' => $lockedCompany->id]);
            $score->fill([
                'volume_points' => $volumePoints,
                'frequency_points' => $frequencyPoints,
                'potential_points' => $potentialPoints,
                'relationship_points' => $relationshipPoints,
                'total_score' => $totalScore,
                'tier' => $tier,
                'is_complete' => $isComplete,
                'last_calculated_at' => CarbonImmutable::now('UTC'),
            ]);
            $score->save();

            $current = $this->snapshot($score);

            if ($previous === null || $previous !== $current) {
                CustomerScoreHistory::query()->create([
                    ...$current,
                    'company_id' => $lockedCompany->id,
                    'reason' => mb_substr($reason, 0, 120),
                    'recorded_at' => CarbonImmutable::now('UTC'),
                ]);
            }

            return $score->refresh();
        });
    }

    /** @param array{potential_points?: int|null, relationship_points?: int|null} $criteria */
    public function updateManualCriteria(Company $company, array $criteria, string $reason): CustomerScore
    {
        return DB::transaction(function () use ($company, $criteria, $reason): CustomerScore {
            $score = CustomerScore::query()->firstOrCreate(
                ['company_id' => $company->id],
                [
                    'volume_points' => 0,
                    'frequency_points' => 0,
                    'potential_points' => null,
                    'relationship_points' => null,
                    'total_score' => null,
                    'tier' => null,
                    'is_complete' => false,
                    'last_calculated_at' => CarbonImmutable::now('UTC'),
                ],
            );
            $score->fill($criteria)->save();

            return $this->recalculateForCompany($company, $reason);
        });
    }

    /** @return array{volume_points: int, frequency_points: int, potential_points: int|null, relationship_points: int|null, total_score: int|null, tier: string|null, is_complete: bool} */
    private function snapshot(CustomerScore $score): array
    {
        return [
            'volume_points' => (int) $score->volume_points,
            'frequency_points' => (int) $score->frequency_points,
            'potential_points' => $score->potential_points === null ? null : (int) $score->potential_points,
            'relationship_points' => $score->relationship_points === null ? null : (int) $score->relationship_points,
            'total_score' => $score->total_score === null ? null : (int) $score->total_score,
            'tier' => $score->tier,
            'is_complete' => (bool) $score->is_complete,
        ];
    }

    /**
     * @param  numeric-string  $amount
     * @param  list<array{max_amount: numeric-string|null, points: int}>  $bands
     */
    private function volumePoints(string $amount, array $bands): int
    {
        foreach ($bands as $band) {
            if ($band['max_amount'] === null || bccomp($amount, $band['max_amount'], 2) <= 0) {
                return (int) $band['points'];
            }
        }

        return 0;
    }

    /** @return numeric-string */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('O agregado financeiro retornou um valor inválido.');
        }

        return $value;
    }

    /** @param list<array{min_purchases: int, points: int}> $bands */
    private function frequencyPoints(int $purchaseCount, array $bands): int
    {
        $points = 0;

        foreach ($bands as $band) {
            if ($purchaseCount >= $band['min_purchases']) {
                $points = (int) $band['points'];
            }
        }

        return $points;
    }

    /** @param list<array{key: string, min_score: int, max_score: int, color: string}> $tiers */
    private function tierForScore(int $score, array $tiers): ?string
    {
        foreach ($tiers as $tier) {
            if ($score >= $tier['min_score'] && $score <= $tier['max_score']) {
                return $tier['key'];
            }
        }

        return null;
    }
}
