<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * The public demo visitor can look at everything and change nothing: any write (and the few GETs
 * with side effects, like starting an OAuth connection) is refused with a friendly 403.
 */
class DemoReadOnly
{
    private const ALLOWED_WRITES = ['api/v1/auth/logout'];

    private const SIDE_EFFECT_GETS = ['api/v1/erp/quickbooks/connect', 'api/v1/erp/quickbooks/callback'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->is_demo) {
            $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $request->is(...self::ALLOWED_WRITES);
            if ($write || $request->is(...self::SIDE_EFFECT_GETS)) {
                return response()->json(['errors' => [[
                    'status' => '403', 'code' => 'demo_read_only', 'title' => 'Read-only demo',
                    'detail' => 'This is a read-only demo with fictional data, so changes are turned off. Run ExtractIQ locally to try everything.',
                ]]], 403);
            }
        }

        return $next($request);
    }
}
