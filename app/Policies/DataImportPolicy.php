<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\DataImport;
use App\Models\User;

class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn(app(TenantContext::class)->id()) !== null;
    }

    public function view(User $user, DataImport $dataImport): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $dataImport->created_by === $user->id
            || in_array($user->roleIn(app(TenantContext::class)->id()), ['owner', 'admin'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DataImport $dataImport): bool
    {
        return $this->view($user, $dataImport);
    }
}
