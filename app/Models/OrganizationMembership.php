<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** @property string $role */
class OrganizationMembership extends Pivot
{
    protected $table = 'organization_user';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
