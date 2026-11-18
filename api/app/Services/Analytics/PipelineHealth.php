<?php

namespace App\Services\Analytics;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\ErpConnection;
use App\Models\ErpPush;
use App\Models\ErpSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A "right now" view of the machinery: queue depth per queue and per job class (read from the
 * Redis queue lists Horizon works from), documents waiting at each pipeline stage, failed jobs,
 * ERP pushes over the last 24 hours and the last sync of every connector.
 */
class PipelineHealth
{
    public const QUEUES = ['pipeline', 'broadcasts', 'default'];

    public function snapshot(int $companyId): array
    {
        return [
            'queues' => $this->queues(),
            'stages' => $this->stages($companyId),
            'failed_jobs' => $this->failedJobs(),
            'failed_steps_24h' => DocumentEvent::withoutGlobalScope('company')->where('company_id', $companyId)
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'erp_24h' => $this->erp24h($companyId),
            'connectors' => $this->connectors($companyId),
        ];
    }

    /** @return array{driver: string, queues: list<array{name: string, pending: int, delayed: int, reserved: int, jobs: array<string, int>}>}|array{driver: string, queues: null} */
    private function queues(): array
    {
        $driver = (string) config('queue.default');
        if (config("queue.connections.$driver.driver") !== 'redis') {
            return ['driver' => $driver, 'queues' => null];
        }
        try {
            $redis = Redis::connection(config("queue.connections.$driver.connection", 'default'));
            $out = [];
            foreach (self::QUEUES as $q) {
                $jobs = [];
                foreach ([...$redis->lrange("queues:$q", 0, 999), ...$redis->zrange("queues:$q:delayed", 0, 999)] as $payload) {
                    $name = class_basename((string) (json_decode((string) $payload, true)['displayName'] ?? 'Unknown'));
                    $jobs[$name] = ($jobs[$name] ?? 0) + 1;
                }
                arsort($jobs);
                $out[] = [
                    'name' => $q, 'pending' => (int) $redis->llen("queues:$q"), 'delayed' => (int) $redis->zcard("queues:$q:delayed"),
                    'reserved' => (int) $redis->zcard("queues:$q:reserved"), 'jobs' => $jobs,
                ];
            }

            return ['driver' => 'redis', 'queues' => $out];
        } catch (Throwable $e) {
            return ['driver' => 'redis', 'queues' => null, 'error' => 'Redis unreachable: '.$e->getMessage()];
        }
    }

    /** Documents waiting at each automated stage, and how long the oldest has waited. */
    private function stages(int $companyId): array
    {
        // Purchase orders and receipts stop at "approved" (they're reference data), so only invoices wait after approval.
        $waiting = Document::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereIn('status', [DocumentStatus::Received->value, DocumentStatus::OcrDone->value, DocumentStatus::Extracted->value])
                ->orWhere(fn ($w) => $w->where('type', DocumentType::Invoice->value)->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Matched->value])))
            ->selectRaw('status, count(*) as c, min(updated_at) as oldest')->groupBy('status')->toBase()->get()->keyBy('status');

        return array_map(fn ($status) => [
            'status' => $status, 'waiting' => (int) ($waiting[$status]->c ?? 0),
            'oldest_at' => isset($waiting[$status]) ? \Illuminate\Support\Carbon::parse($waiting[$status]->oldest)->toIso8601String() : null,
        ], ['received', 'ocr_done', 'extracted', 'approved', 'matched']);
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['last_24h' => 0, 'last_7d' => 0, 'by_job' => [], 'latest_at' => null];
        }
        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->get(['payload', 'failed_at']);
        $byJob = [];
        foreach ($recent as $f) {
            $name = class_basename((string) (json_decode((string) $f->payload, true)['displayName'] ?? 'Unknown'));
            $byJob[$name] = ($byJob[$name] ?? 0) + 1;
        }
        arsort($byJob);

        return [
            'last_24h' => $recent->filter(fn ($f) => \Illuminate\Support\Carbon::parse($f->failed_at)->gte(now()->subDay()))->count(),
            'last_7d' => $recent->count(),
            'by_job' => $byJob,
            'latest_at' => ($latest = $recent->max('failed_at')) ? \Illuminate\Support\Carbon::parse($latest)->toIso8601String() : null,
        ];
    }

    private function erp24h(int $companyId): array
    {
        $c = ErpPush::withoutGlobalScope('company')->where('company_id', $companyId)->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $ok = (int) ($c['succeeded'] ?? 0);
        $dead = (int) ($c['dead'] ?? 0);

        return ['attempts' => (int) $c->sum(), 'succeeded' => $ok, 'failed' => (int) ($c['failed'] ?? 0), 'dead' => $dead,
            'success_rate' => $ok + $dead ? round($ok / ($ok + $dead), 4) : null];
    }

    private function connectors(int $companyId): array
    {
        $active = ErpConnection::activeFor($companyId)->connector;
        $runs = ErpSyncRun::withoutGlobalScope('company')->where('company_id', $companyId)->orderByDesc('started_at')->get()->unique('connector')->keyBy('connector');

        return array_map(function ($name) use ($companyId, $active, $runs) {
            $c = ErpConnection::for($companyId, $name);
            $run = $runs->get($name);

            return [
                'connector' => $name, 'is_active' => $name === $active, 'status' => $c->status,
                'last_sync_at' => $c->last_sync_at?->toIso8601String(),
                'last_run' => $run ? ['status' => $run->status, 'started_at' => $run->started_at->toIso8601String(), 'errors' => $run->errors] : null,
            ];
        }, ErpConnection::CONNECTORS);
    }
}
