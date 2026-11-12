<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateCommercialSettingsRequest;
use App\Models\CommercialSetting;
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

        return Inertia::render('commercial/settings', [
            'settings' => $settingsService->forOrganization($organization),
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

    public function update(UpdateCommercialSettingsRequest $request, CommercialSettingsService $settingsService): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $settingsService->forOrganization($organization);

        DB::transaction(function () use ($request): void {
            $settings = CommercialSetting::query()->lockForUpdate()->firstOrFail();
            $settings->update($request->validated());
        });

        return redirect()->route('commercial-settings.edit')->with('success', 'Configurações comerciais atualizadas.');
    }
}
