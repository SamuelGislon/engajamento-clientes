<?php

namespace App\Jobs;

use App\Domain\Commercial\CustomerScoreService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialScoreRecalculation;
use App\Models\CommercialSetting;
use App\Models\Company;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecalculateOrganizationCustomerScores implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $organizationId, public int $recalculationId, public int $attemptNumber) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(CustomerScoreService $scores): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        app(TenantContext::class)->set($organization);

        $hasMore = DB::transaction(function () use ($scores): bool {
            CommercialSetting::query()
                ->where('organization_id', $this->organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $recalculation = CommercialScoreRecalculation::query()
                ->where('organization_id', $this->organizationId)
                ->lockForUpdate()
                ->findOrFail($this->recalculationId);

            if ($recalculation->attempt_number !== $this->attemptNumber
                || in_array($recalculation->status, ['completed', 'failed', 'superseded'], true)) {
                return false;
            }

            $latestRecalculationId = CommercialScoreRecalculation::query()
                ->where('organization_id', $this->organizationId)
                ->max('id');

            if ((int) $latestRecalculationId > (int) $recalculation->id) {
                $recalculation->update([
                    'status' => 'superseded',
                    'completed_at' => Carbon::now('UTC'),
                    'last_error' => 'Uma configuração mais recente iniciou outro recálculo.',
                ]);

                return false;
            }

            $recalculation->update([
                'status' => 'processing',
                'started_at' => $recalculation->started_at ?? Carbon::now('UTC'),
                'last_error' => null,
                'failed_at' => null,
            ]);

            /** @var Collection<int, Company> $companies */
            $companies = Company::query()
                ->where('organization_id', $this->organizationId)
                ->whereHas('customerScore')
                ->when($recalculation->last_company_id !== null, function ($query) use ($recalculation): void {
                    $query->where('id', '>', $recalculation->last_company_id);
                })
                ->orderBy('id')
                ->limit(50)
                ->get();

            if ($companies->isEmpty()) {
                $recalculation->update([
                    'status' => 'completed',
                    'completed_at' => Carbon::now('UTC'),
                ]);

                return false;
            }

            foreach ($companies as $company) {
                $scores->recalculateForCompany(
                    $company,
                    $recalculation->reason,
                    true,
                    $recalculation->settings_snapshot,
                );
            }

            $lastCompanyId = (int) $companies->last()->id;
            $remainingCompanies = Company::query()
                ->where('organization_id', $this->organizationId)
                ->whereHas('customerScore')
                ->where('id', '>', $lastCompanyId)
                ->count();
            $processedCompanies = $recalculation->processed_companies + $companies->count();
            $hasMoreCompanies = $remainingCompanies > 0;

            $recalculation->update([
                'last_company_id' => $lastCompanyId,
                'processed_companies' => $processedCompanies,
                'total_companies' => $processedCompanies + $remainingCompanies,
                'status' => $hasMoreCompanies ? 'processing' : 'completed',
                'completed_at' => $hasMoreCompanies ? null : Carbon::now('UTC'),
            ]);

            return $hasMoreCompanies;
        });

        if ($hasMore) {
            self::dispatch($this->organizationId, $this->recalculationId, $this->attemptNumber)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        app(TenantContext::class)->set($organization);

        DB::transaction(function (): void {
            $recalculation = CommercialScoreRecalculation::query()
                ->where('organization_id', $this->organizationId)
                ->lockForUpdate()
                ->find($this->recalculationId);

            if ($recalculation === null
                || $recalculation->attempt_number !== $this->attemptNumber
                || ! in_array($recalculation->status, ['queued', 'processing'], true)) {
                return;
            }

            $recalculation->update([
                'status' => 'failed',
                'failed_at' => Carbon::now('UTC'),
                'last_error' => 'A execução foi interrompida após esgotar as tentativas. Ela pode ser retomada.',
            ]);
        });
    }
}
