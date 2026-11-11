<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyContact extends Pivot
{
    protected $table = 'company_contacts';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['roles' => 'array', 'is_primary' => 'boolean'];
    }
}
