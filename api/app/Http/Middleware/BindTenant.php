<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Binds the authenticated user's company so BelongsToCompany scopes every query. */
class BindTenant
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(Request $request, Closure $next)
    {
        $this->tenant->set($request->user()?->company_id);

        return $next($request);
    }
}
