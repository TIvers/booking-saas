<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (! app()->bound('currentTenant')) {
                throw new LogicException('A tenant context is required to create this record.');
            }

            $model->setAttribute('tenant_id', app('currentTenant')->getKey());
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
