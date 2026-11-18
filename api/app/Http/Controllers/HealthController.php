<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * GET /api/health — for the uptime check and the load balancer. 200 when the API can serve
 * requests (database + Redis up), 503 otherwise. Storage, queue workers and the scheduler are
 * reported too; if one of those is down the status is "degraded" (documents would stall).
 * Never includes hostnames, credentials or error details beyond a short reason.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') && true),
            'redis' => $this->check(fn () => (bool) Redis::connection()->ping()),
            'storage' => $this->check(function () {
                $disk = Storage::disk(config('extractiq.documents_disk'));
                $disk->put('.health', now()->toIso8601String());

                return $disk->exists('.health');
            }),
            'queue' => $this->check(function () {
                $masters = app(MasterSupervisorRepository::class)->all();
                if (! $masters) {
                    return 'no Horizon workers running';
                }

                return ['workers' => count($masters), 'pending_jobs' => Queue::size('default')];
            }),
            'scheduler' => $this->check(function () {
                $beat = Cache::get('extractiq:scheduler-heartbeat');
                if (! $beat) {
                    return 'no heartbeat yet';
                }
                $age = Carbon::parse($beat)->diffInSeconds(now());

                return $age <= 180 ? ['last_run_seconds_ago' => (int) $age] : 'last heartbeat '.(int) $age.'s ago';
            }),
        ];

        $critical = $checks['database']['ok'] && $checks['redis']['ok'];
        $all = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'status' => ! $critical ? 'down' : ($all ? 'ok' : 'degraded'),
            'version' => config('app.version'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $critical ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    /** @return array{ok: bool, ms: int, detail?: mixed} */
    private function check(callable $probe): array
    {
        $t = microtime(true);
        try {
            $result = $probe();
            $ok = $result === true || is_array($result);
            $out = ['ok' => $ok, 'ms' => (int) round((microtime(true) - $t) * 1000)];
            if (is_array($result)) {
                $out['detail'] = $result;
            } elseif (is_string($result)) {
                $out['detail'] = $result;
                $out['ok'] = false;
            } elseif (! $ok) {
                $out['detail'] = 'check failed';
            }

            return $out;
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000), 'detail' => class_basename($e)];
        }
    }
}
