<?php

namespace App\Policies;

use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn(app(TenantContext::class)->id()) !== null;
    }

    public function view(User $user, Company $company): bool
    {
        return app(CommercialAccessScope::class)->canViewCompany($user, $company, app(TenantContext::class)->id());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->view($user, $company);
    }

    public function delete(User $user): bool
    {
        return in_array($user->roleIn(app(TenantContext::class)->id()), ['owner', 'admin'], true);
    }
}
