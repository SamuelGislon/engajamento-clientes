<?php

namespace App\Http\Controllers;

use App\Domain\Teams\OrganizationMemberService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateOrganizationMemberRequest;
use Illuminate\Http\RedirectResponse;

class OrganizationMemberController extends Controller
{
    public function update(
        UpdateOrganizationMemberRequest $request,
        int $user,
        OrganizationMemberService $members,
    ): RedirectResponse {
        $organization = app(TenantContext::class)->organization();
        abort_if($organization === null, 404);

        $members->update($organization, $user, $request->user(), $request->memberAttributes());

        return back()->with('success', 'Participação atualizada.');
    }
}
