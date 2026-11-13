<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateCommercialSettingsRequest;
use App\Jobs\RecalculateOrganizationCustomerScores;
use App\Models\CommercialScoreRecalculation;
use App\Models\CommercialSetting;
use App\Models\CustomerScore;
use App\Models\JourneyTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CommercialSettingsController extends Controller
{
    public function edit(Request $request, CommercialSettingsService $settingsService): Response
    {
        Gate::authorize('manage', CommercialSetting::class);
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $actor = $request->user();
        abort_if($actor === null, 403);
        $latestScoreRecalculation = CommercialScoreRecalculation::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first(['id', 'status', 'total_companies', 'processed_companies', 'last_error', 'updated_at']);
        $scoreRecalculation = $latestScoreRecalculation === null
            ? null
            : [
                'id' => $latestScoreRecalculation->id,
                'status' => $latestScoreRecalculation->status,
                'last_error' => $latestScoreRecalculation->last_error,
                'updated_at' => $latestScoreRecalculation->updated_at,
                ...in_array($actor->roleIn($organization->id), ['owner', 'admin'], true) ? [
                    'total_companies' => $latestScoreRecalculation->total_companies,
                    'processed_companies' => $latestScoreRecalculation->processed_companies,
                ] : [],
            ];

        return Inertia::render('commercial/settings', [
            'settings' => $settingsService->forOrganization($organization),
            'scoreRecalculation' => $scoreRecalculation,
            'journeyTemplates' => JourneyTemplate::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->where(fn ($templates) => $templates->where('type', 'post_sale')->where('trigger_type', 'first_sale'))
                        ->orWhere(fn ($templates) => $templates->where('type', 'reactivation')->where('trigger_type', 'sales_inactivity'));
                })
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'trigger_type']),
        ]);
    }

    public function update(
        UpdateCommercialSettingsRequest $request,
        CommercialSettingsService $settingsService,
    ): RedirectResponse {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $settingsService->forOrganization($organization);
        $actor = $request->user();
        abort_if($actor === null, 403);
        $attributes = $request->validated();

        $recalculationId = DB::transaction(function () use ($actor, $organization, $attributes): ?int {
            DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            Gate::authorize('manage', CommercialSetting::class);

            $settings = CommercialSetting::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousRules = $this->scoreRuleSnapshot($settings);
            $settings->update($attributes);
            $settings->refresh();

            if ($previousRules === $this->scoreRuleSnapshot($settings)) {
                return null;
            }

            $recalculation = CommercialScoreRecalculation::query()->create([
                'requested_by' => $actor->id,
                'status' => 'queued',
                'total_companies' => CustomerScore::query()
                    ->where('organization_id', $organization->id)
                    ->count(),
                'processed_companies' => 0,
                'reason' => 'Configurações comerciais atualizadas',
                'settings_snapshot' => [
                    'scoring_weights' => $settings->scoring_weights,
                    'volume_score_bands' => $settings->volume_score_bands,
                    'frequency_score_bands' => $settings->frequency_score_bands,
                    'tiers' => $settings->tiers,
                ],
            ]);

            return (int) $recalculation->id;
        });

        if ($recalculationId !== null) {
            RecalculateOrganizationCustomerScores::dispatch($organization->id, $recalculationId, 1)->afterCommit();
        }

        return redirect()->route('commercial-settings.edit')->with('success', 'Configurações comerciais atualizadas.');
    }

    public function resumeScoreRecalculation(Request $request, int $recalculationId): RedirectResponse
    {
        Gate::authorize('manage', CommercialSetting::class);
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $actor = $request->user();
        abort_if($actor === null, 403);

        $attemptNumber = DB::transaction(function () use ($actor, $organization, $recalculationId): int {
            DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            Gate::authorize('manage', CommercialSetting::class);

            $recalculation = CommercialScoreRecalculation::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($recalculationId);

            abort_if(in_array($recalculation->status, ['completed', 'superseded'], true), 409);
            abort_if(
                $recalculation->status === 'processing'
                    && $recalculation->updated_at?->isAfter(now()->subMinutes(10)),
                409,
            );

            $attemptNumber = $recalculation->attempt_number + 1;
            $recalculation->update([
                'status' => 'queued',
                'attempt_number' => $attemptNumber,
                'last_error' => null,
                'failed_at' => null,
                'completed_at' => null,
            ]);

            return $attemptNumber;
        });

        RecalculateOrganizationCustomerScores::dispatch($organization->id, $recalculationId, $attemptNumber)->afterCommit();

        return redirect()->route('commercial-settings.edit')->with('success', 'O recálculo foi colocado novamente na fila.');
    }

    /** @return array{
     *     weights: array{volume: int, frequency: int, potential: int, relationship: int},
     *     volume_bands: list<array{max_amount: numeric-string|null, points: int}>,
     *     frequency_bands: list<array{min_purchases: int, points: int}>,
     *     tier_limits: list<array{key: string, min_score: int, max_score: int}>
     * }
     */
    private function scoreRuleSnapshot(CommercialSetting $settings): array
    {
        return [
            'weights' => $settings->scoring_weights,
            'volume_bands' => $settings->volume_score_bands,
            'frequency_bands' => $settings->frequency_score_bands,
            'tier_limits' => array_map(static fn (array $tier): array => [
                'key' => $tier['key'],
                'min_score' => $tier['min_score'],
                'max_score' => $tier['max_score'],
            ], $settings->tiers),
        ];
    }
}
