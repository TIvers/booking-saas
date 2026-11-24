<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('currentTenant')) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            app('currentTenant')->getKey(),
        );
    }
}
