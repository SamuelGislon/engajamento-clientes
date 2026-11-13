<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\CommercialSettingsService;
use App\Domain\Journeys\JourneyTemplateService;
use App\Domain\Organizations\OnboardingAccessService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateOnboardingJourneysRequest;
use App\Http\Requests\UpdateOnboardingOrganizationRequest;
use App\Http\Requests\UpdateOnboardingRelationshipRequest;
use App\Models\JourneyTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(
        Request $request,
        CommercialSettingsService $settingsService,
        OnboardingAccessService $onboardingAccess,
    ): Response|RedirectResponse {
        $organization = $this->organizationForOwner($request, $onboardingAccess);

        if ($organization->onboarding_step === null || $organization->onboarding_completed_at !== null) {
            return redirect()->route('dashboard');
        }

        $settings = $settingsService->forOrganization($organization);

        return Inertia::render('onboarding/index', [
            'step' => $organization->onboarding_step,
            'organization' => $organization->only(['id', 'name', 'timezone', 'auto_enroll_default_journey']),
            'commercialSettings' => $settings->only(['sales_inactivity_days', 'relationship_inactivity_days']),
            'journeys' => JourneyTemplate::query()
                ->where('type', 'prospecting')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
            'timezones' => [
                'America/Sao_Paulo',
                'America/Manaus',
                'America/Rio_Branco',
                'America/Noronha',
                'America/New_York',
                'Europe/Lisbon',
                'UTC',
            ],
        ]);
    }

    public function updateOrganization(
        UpdateOnboardingOrganizationRequest $request,
        OnboardingAccessService $onboardingAccess,
    ): RedirectResponse {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $actor = $this->actor($request);

        DB::transaction(function () use ($organization, $request, $onboardingAccess, $actor): void {
            $lockedOrganization = $onboardingAccess->lockForOwner($organization, $actor, 'organization');
            $lockedOrganization->update([
                ...$request->validated(),
                'onboarding_step' => 'relationship',
            ]);
        });

        return redirect()->route('onboarding.show')->with('success', 'Dados da organização salvos.');
    }

    public function updateRelationship(
        UpdateOnboardingRelationshipRequest $request,
        CommercialSettingsService $settingsService,
        OnboardingAccessService $onboardingAccess,
    ): RedirectResponse {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $actor = $this->actor($request);

        DB::transaction(function () use ($organization, $request, $settingsService, $onboardingAccess, $actor): void {
            $lockedOrganization = $onboardingAccess->lockForOwner($organization, $actor, 'relationship');
            $settings = $settingsService->forOrganization($lockedOrganization);
            $settings->update($request->validated());
            $lockedOrganization->update(['onboarding_step' => 'journeys']);
        });

        return redirect()->route('onboarding.show')->with('success', 'Configuração inicial salva.');
    }

    public function updateJourneys(
        UpdateOnboardingJourneysRequest $request,
        JourneyTemplateService $templates,
        OnboardingAccessService $onboardingAccess,
    ): RedirectResponse {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $validated = $request->validated();
        $templateId = (int) $validated['default_journey_template_id'];
        $actor = $this->actor($request);

        DB::transaction(function () use ($organization, $validated, $templateId, $templates, $onboardingAccess, $actor): void {
            $lockedOrganization = $onboardingAccess->lockForOwner($organization, $actor, 'journeys');
            $template = JourneyTemplate::query()
                ->where('type', 'prospecting')
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($templateId);

            $templates->save($template, [
                'name' => $template->name,
                'description' => $template->description,
                'type' => 'prospecting',
                'trigger_type' => 'company_created',
                'is_active' => true,
                'is_default' => true,
            ]);

            $lockedOrganization->update([
                'auto_enroll_default_journey' => $validated['auto_enroll_default_journey'],
                'onboarding_step' => 'invite',
            ]);
        });

        return redirect()->route('onboarding.show')->with('success', 'Preferências de jornadas salvas.');
    }

    public function advance(Request $request, OnboardingAccessService $onboardingAccess): RedirectResponse
    {
        $organization = $this->organizationForOwner($request, $onboardingAccess);
        $actor = $this->actor($request);
        abort_unless($organization->onboarding_step === 'invite', 403);

        DB::transaction(function () use ($organization, $onboardingAccess, $actor): void {
            $lockedOrganization = $onboardingAccess->lockForOwner($organization, $actor, 'invite');
            $lockedOrganization->update(['onboarding_step' => 'portfolio']);
        });

        return redirect()->route('onboarding.show');
    }

    public function complete(Request $request, OnboardingAccessService $onboardingAccess): RedirectResponse
    {
        $organization = $this->organizationForOwner($request, $onboardingAccess);
        $actor = $this->actor($request);
        abort_unless($organization->onboarding_step === 'portfolio', 403);

        DB::transaction(function () use ($organization, $onboardingAccess, $actor): void {
            $lockedOrganization = $onboardingAccess->lockForOwner($organization, $actor, 'portfolio');
            $lockedOrganization->update([
                'onboarding_step' => null,
                'onboarding_completed_at' => now(),
            ]);
        });

        return redirect()->route('dashboard')->with('success', 'Configuração inicial concluída.');
    }

    private function organizationForOwner(Request $request, OnboardingAccessService $onboardingAccess): Organization
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $onboardingAccess->assertOwner($organization, $this->actor($request));

        return $organization;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
