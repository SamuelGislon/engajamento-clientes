<?php

namespace App\Http\Controllers;

use App\Domain\Journeys\JourneyTemplateService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveJourneyTemplateRequest;
use App\Http\Requests\UpdateJourneySettingsRequest;
use App\Models\JourneyTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JourneyTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', JourneyTemplate::class);
        $user = $request->user();
        $templates = JourneyTemplate::query()
            ->withCount('steps')
            ->withCount(['enrollments as active_enrollments_count' => fn ($query) => $query
                ->whereIn('status', ['active', 'paused'])
                ->whereHas('company', fn ($companies) => $companies->visibleTo($user))
                ->when($user->roleIn(app(TenantContext::class)->id()) === 'salesperson', fn ($enrollments) => $enrollments->where('assigned_to', $user->id))])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('journeys/index', [
            'templates' => $templates,
            'autoEnrollDefaultJourney' => app(TenantContext::class)->organization()->auto_enroll_default_journey,
            'canManage' => $user->can('create', JourneyTemplate::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', JourneyTemplate::class);

        return Inertia::render('journeys/form', ['template' => null, 'canEditSteps' => true]);
    }

    public function edit(JourneyTemplate $template): Response
    {
        Gate::authorize('update', $template);
        $template->load('steps');

        return Inertia::render('journeys/form', [
            'template' => $template,
            'canEditSteps' => ! $template->enrollments()->exists(),
        ]);
    }

    public function store(SaveJourneyTemplateRequest $request, JourneyTemplateService $templates): RedirectResponse
    {
        $templates->save(null, $request->validated());

        return redirect()->route('journeys.index')->with('success', 'Modelo de jornada criado.');
    }

    public function update(SaveJourneyTemplateRequest $request, JourneyTemplate $template, JourneyTemplateService $templates): RedirectResponse
    {
        $templates->save($template, $request->validated());

        return redirect()->route('journeys.index')->with('success', 'Modelo de jornada atualizado.');
    }

    public function updateSettings(UpdateJourneySettingsRequest $request): RedirectResponse
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 403);
        $organization->update($request->validated());

        return back()->with('success', 'Configuração de autoinscrição atualizada.');
    }
}
