<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! in_array($request->user()?->role?->value, $roles, true)) {
            return response()->json(['errors' => [[
                'status' => '403', 'code' => 'forbidden', 'title' => 'Forbidden',
                'detail' => 'Your role does not allow this action.',
            ]]], 403);
        }

        return $next($request);
    }
}
