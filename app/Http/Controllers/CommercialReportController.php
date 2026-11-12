<?php

namespace App\Http\Controllers;

use App\Domain\Teams\CommercialReportService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\IndexCommercialReportsRequest;
use Inertia\Inertia;
use Inertia\Response;

class CommercialReportController extends Controller
{
    public function __invoke(IndexCommercialReportsRequest $request, CommercialReportService $reports): Response
    {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);
        $filters = $request->validated();

        return Inertia::render('reports/index', $reports->generate($request->user(), $organization, $filters));
    }
}
