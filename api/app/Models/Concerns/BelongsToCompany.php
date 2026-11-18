<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes every query to the current tenant (when one is bound) and stamps
 * company_id on create. Queue jobs run without a bound tenant and rely on
 * explicit ids instead.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if ($id = app(Tenant::class)->id()) {
                $query->where($query->qualifyColumn('company_id'), $id);
            }
        });

        static::creating(function ($model) {
            if (! $model->company_id && ($id = app(Tenant::class)->id())) {
                $model->company_id = $id;
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
