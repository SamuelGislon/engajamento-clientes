<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => [
                'required',
                Rule::exists('organization_user', 'organization_id')
                    ->where('user_id', $request->user()->id),
            ],
        ]);

        $request->session()->put('active_organization_id', (int) $validated['organization_id']);

        return redirect()->route('dashboard');
    }
}
