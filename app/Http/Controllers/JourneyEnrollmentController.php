<?php

namespace App\Http\Controllers;

use App\Domain\Journeys\JourneyService;
use App\Http\Requests\StoreCompanyJourneyRequest;
use App\Models\Company;
use App\Models\JourneyEnrollment;
use App\Models\JourneyTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class JourneyEnrollmentController extends Controller
{
    public function store(StoreCompanyJourneyRequest $request, Company $company, JourneyService $journeys): RedirectResponse
    {
        $template = JourneyTemplate::query()->whereKey((int) $request->validated('journey_template_id'))->firstOrFail();
        $journeys->enrollManually($company, $template, $request->user());

        return redirect()->route('companies.show', $company)->with('success', 'Jornada iniciada.');
    }

    public function pause(JourneyEnrollment $enrollment, JourneyService $journeys): RedirectResponse
    {
        Gate::authorize('pause', $enrollment);
        $journeys->pause($enrollment);

        return back()->with('success', 'Jornada pausada.');
    }

    public function resume(JourneyEnrollment $enrollment, JourneyService $journeys): RedirectResponse
    {
        Gate::authorize('resume', $enrollment);
        $journeys->resume($enrollment);

        return back()->with('success', 'Jornada retomada.');
    }

    public function cancel(JourneyEnrollment $enrollment, JourneyService $journeys): RedirectResponse
    {
        Gate::authorize('cancel', $enrollment);
        $journeys->cancel($enrollment);

        return back()->with('success', 'Jornada cancelada.');
    }
}
