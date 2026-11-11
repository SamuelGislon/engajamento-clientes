<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $organizationId = app(TenantContext::class)->id();

            if ($organizationId === null) {
                if (app()->runningInConsole() && $model->getAttribute('organization_id')) {
                    return;
                }

                throw new LogicException('An active organization is required to create this record.');
            }

            if ($model->getAttribute('organization_id') !== null
                && (int) $model->getAttribute('organization_id') !== $organizationId) {
                throw new AuthorizationException('The record does not belong to the active organization.');
            }

            $model->setAttribute('organization_id', $organizationId);
        });
    }
}
