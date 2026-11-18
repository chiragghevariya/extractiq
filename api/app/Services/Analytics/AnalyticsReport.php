<?php

namespace App\Services\Analytics;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers GET /analytics from analytics_daily. Past days come from the rollup (missing days are
 * backfilled on demand); today is computed live, so the numbers are never a day stale.
 */
class AnalyticsReport
{
    /** How a document's status is grouped in the volume chart. */
    public const STATUS_GROUPS = [
        'in_erp' => ['pushed', 'posted', 'paid'],
        'in_progress' => ['received', 'ocr_done', 'extracted', 'approved', 'matched'],
        'in_review' => ['needs_review', 'validated'],
        'stopped' => ['match_exception', 'push_failed', 'failed', 'rejected'],
    ];

    public function __construct(private readonly AnalyticsRollup $rollup) {}

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, group_by: string, vendor?: ?int, type?: ?string, connector?: ?string}  $f
     */
    public function build(int $companyId, array $f): array
    {
        $rows = $this->rows($companyId, $f['from'], $f['to'], $f);
        $days = (int) $f['from']->diffInDays($f['to']) + 1;
        $prevTo = $f['from']->subDay();
        $prevFrom = $prevTo->subDays($days - 1);
        $prev = $this->rows($companyId, $prevFrom, $prevTo, $f);

        return [
            'period' => [
                'from' => $f['from']->toDateString(), 'to' => $f['to']->toDateString(), 'days' => $days, 'group_by' => $f['group_by'],
                'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            ],
            'kpis' => $this->kpis($companyId, $rows, $prev, $f),
            'series' => $this->series($rows, $f),
            'processing_distribution' => $this->distribution($rows),
            'top_vendors' => $this->topVendors($companyId, $rows),
            'exception_reasons' => $this->reasons($rows),
            'accuracy_trend' => $this->accuracyTrend($rows, $f['from'], $f['to']),
            'erp' => $this->erp($rows),
        ];
    }

    /** Rollup rows for [from, to], filtered. @return Collection<int, array<string, mixed>> */
    public function rows(int $companyId, CarbonImmutable $from, CarbonImmutable $to, array $f = []): Collection
    {
        $today = CarbonImmutable::today();
        $pastEnd = $to->lt($today) ? $to : $today->subDay();
        $rows = collect();

        if ($from->lte($pastEnd)) {
            $this->backfill($companyId, $from, $pastEnd);
            $rows = DB::table('analytics_daily')->where('company_id', $companyId)
                ->whereBetween('day', [$from->toDateString(), $pastEnd->toDateString()])
                ->when($f['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
                ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->when($f['connector'] ?? null, fn ($q, $v) => $q->where('connector', $v))
                ->get()->map(fn ($r) => self::decode((array) $r));
        }
        if ($to->gte($today)) {
            $live = collect($this->rollup->compute($companyId, $today, $today))
                ->filter(fn ($r) => (! ($f['vendor'] ?? null) || $r['vendor_id'] === (int) $f['vendor'])
                    && (! ($f['type'] ?? null) || $r['type'] === $f['type'])
                    && (! ($f['connector'] ?? null) || $r['connector'] === $f['connector']));
            $rows = $rows->concat($live);
        }

        return $rows->values();
    }

    /** Roll up any day in [from, to] that has never been computed. */
    public function backfill(int $companyId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $have = DB::table('analytics_rollup_days')->where('company_id', $companyId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])->pluck('day')
            ->map(fn ($d) => substr((string) $d, 0, 10))->flip();
        $missing = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            if (! isset($have[$d->toDateString()])) {
                $missing[] = $d;
            }
        }
        if ($missing) {
            $this->rollup->store($companyId, $missing[0], end($missing));
        }
    }

    // ---- sections -----------------------------------------------------------------------------

    private function kpis(int $companyId, Collection $rows, Collection $prev, array $f): array
    {
        $calc = function (Collection $r) {
            $s = fn ($k) => $r->sum($k);
            $cost = $s('llm_cost_usd') + $s('ocr_cost_usd');

            return [
                'documents_processed' => (int) $s('processed'),
                'documents_received' => (int) $s('received'),
                'touchless_rate' => $s('pushed') ? round($s('touchless') / $s('pushed'), 4) : null,
                'pushed' => (int) $s('pushed'),
                'field_accuracy' => $s('fields_total') ? round(1 - $s('fields_corrected') / $s('fields_total'), 4) : null,
                'fields_total' => (int) $s('fields_total'),
                'fields_corrected' => (int) $s('fields_corrected'),
                'avg_processing_ms' => $s('pushed') ? (int) round($s('processing_ms_sum') / $s('pushed')) : null,
                'cost_per_document_usd' => $s('received') ? round($cost / $s('received'), 6) : null,
                'llm_cost_usd' => round((float) $s('llm_cost_usd'), 6),
                'ocr_cost_usd' => round((float) $s('ocr_cost_usd'), 6),
            ];
        };
        $now = $calc($rows);
        $before = $calc($prev);

        // Open exceptions are a "right now" number, not a period one.
        $open = Document::query()->where('company_id', $companyId)
            ->when($f['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->whereIn('status', [DocumentStatus::MatchException->value, DocumentStatus::PushFailed->value])
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return $now + [
            'exceptions_open' => (int) ($open[DocumentStatus::MatchException->value] ?? 0),
            'push_failed_open' => (int) ($open[DocumentStatus::PushFailed->value] ?? 0),
            'previous' => $before,
        ];
    }

    private function series(Collection $rows, array $f): array
    {
        $buckets = [];
        for ($d = $f['from']; $d->lte($f['to']); $d = $d->addDay()) {
            $buckets[self::periodKey($d, $f['group_by'])] ??= ['period' => self::periodKey($d, $f['group_by'])] + array_fill_keys(array_keys(self::STATUS_GROUPS), 0)
                + ['received' => 0, 'processed' => 0, 'pushed' => 0, 'touchless' => 0, 'fields_total' => 0, 'fields_corrected' => 0, 'processing_ms_sum' => 0,
                    'llm_cost_usd' => 0.0, 'ocr_cost_usd' => 0.0, 'erp_attempts' => 0, 'erp_succeeded' => 0, 'erp_dead' => 0];
        }
        foreach ($rows as $r) {
            $k = self::periodKey(CarbonImmutable::parse($r['day']), $f['group_by']);
            if (! isset($buckets[$k])) {
                continue;
            }
            foreach (['received', 'processed', 'pushed', 'touchless', 'fields_total', 'fields_corrected', 'processing_ms_sum', 'llm_cost_usd', 'ocr_cost_usd', 'erp_attempts', 'erp_succeeded', 'erp_dead'] as $m) {
                $buckets[$k][$m] += $r[$m];
            }
            foreach ((array) $r['status_counts'] as $status => $n) {
                $buckets[$k][self::group($status)] += $n;
            }
        }

        $out = [];
        foreach ($buckets as $b) {
            $out[] = $b + [
                'touchless_rate' => $b['pushed'] ? round($b['touchless'] / $b['pushed'], 4) : null,
                'field_accuracy' => $b['fields_total'] ? round(1 - $b['fields_corrected'] / $b['fields_total'], 4) : null,
                'avg_processing_ms' => $b['pushed'] ? (int) round($b['processing_ms_sum'] / $b['pushed']) : null,
            ];
        }

        return $out;
    }

    private function distribution(Collection $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            foreach ((array) $r['processing_hist'] as $k => $n) {
                $counts[$k] = ($counts[$k] ?? 0) + $n;
            }
        }

        return array_map(fn ($key) => ['bucket' => $key, 'label' => AnalyticsRollup::BUCKETS[$key][0], 'count' => $counts[$key] ?? 0], array_keys(AnalyticsRollup::BUCKETS));
    }

    /** Top 8 vendors by approved invoice value, per currency (no conversion: amounts stay in their own currency). */
    private function topVendors(int $companyId, Collection $rows): array
    {
        $spend = [];
        foreach ($rows as $r) {
            foreach ((array) $r['spend'] as $cur => $minor) {
                $spend[$cur][$r['vendor_id'] ?? 0] = ($spend[$cur][$r['vendor_id'] ?? 0] ?? 0) + $minor;
            }
        }
        $names = Vendor::query()->where('company_id', $companyId)->pluck('name', 'id');
        uasort($spend, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        return array_values(array_map(function ($byVendor, $cur) use ($names) {
            arsort($byVendor);

            return [
                'currency' => $cur, 'total_minor' => (int) array_sum($byVendor), 'vendor_count' => count($byVendor),
                'vendors' => array_map(fn ($id, $minor) => ['id' => $id ? (string) $id : null, 'name' => $id ? ($names[$id] ?? 'Deleted vendor') : 'Unknown vendor', 'spend_minor' => (int) $minor],
                    array_keys(array_slice($byVendor, 0, 8, true)), array_slice($byVendor, 0, 8, true)),
            ];
        }, $spend, array_keys($spend)));
    }

    private function reasons(Collection $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            foreach ((array) $r['exception_reasons'] as $code => $n) {
                $counts[$code] = ($counts[$code] ?? 0) + $n;
            }
        }
        arsort($counts);

        return array_map(fn ($code, $n) => ['code' => $code, 'count' => $n], array_keys($counts), $counts);
    }

    /** Weekly accuracy for all approved documents, and for those read with learned vendor hints. */
    private function accuracyTrend(Collection $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weeks = [];
        for ($d = $from->startOfWeek(); $d->lte($to); $d = $d->addWeek()) {
            $weeks[$d->toDateString()] = ['week' => $d->toDateString(), 'fields_total' => 0, 'fields_corrected' => 0, 'hinted_docs' => 0, 'hinted_fields_total' => 0, 'hinted_fields_corrected' => 0];
        }
        foreach ($rows as $r) {
            $k = CarbonImmutable::parse($r['day'])->startOfWeek()->toDateString();
            if (isset($weeks[$k])) {
                foreach (['fields_total', 'fields_corrected', 'hinted_docs', 'hinted_fields_total', 'hinted_fields_corrected'] as $m) {
                    $weeks[$k][$m] += $r[$m];
                }
            }
        }

        return array_values(array_map(fn ($w) => $w + [
            'accuracy' => $w['fields_total'] ? round(1 - $w['fields_corrected'] / $w['fields_total'], 4) : null,
            'hinted_accuracy' => $w['hinted_fields_total'] ? round(1 - $w['hinted_fields_corrected'] / $w['hinted_fields_total'], 4) : null,
        ], $weeks));
    }

    private function erp(Collection $rows): array
    {
        $attempts = (int) $rows->sum('erp_attempts');
        $ok = (int) $rows->sum('erp_succeeded');
        $dead = (int) $rows->sum('erp_dead');

        return [
            'attempts' => $attempts, 'succeeded' => $ok, 'dead' => $dead, 'retried' => max(0, $attempts - $ok - $dead),
            // Of the bills that reached a final outcome, how many landed.
            'success_rate' => $ok + $dead ? round($ok / ($ok + $dead), 4) : null,
        ];
    }

    // ---- helpers ----------------------------------------------------------------------------

    public static function group(string $status): string
    {
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'in_progress';
    }

    public static function periodKey(CarbonImmutable $d, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => $d->startOfWeek()->toDateString(),
            'month' => $d->startOfMonth()->toDateString(),
            default => $d->toDateString(),
        };
    }

    private static function decode(array $r): array
    {
        foreach (['status_counts', 'processing_hist', 'spend', 'exception_reasons'] as $k) {
            $r[$k] = $r[$k] ? json_decode((string) $r[$k], true) : [];
        }
        foreach (['llm_cost_usd', 'ocr_cost_usd'] as $k) {
            $r[$k] = (float) $r[$k];
        }
        $r['vendor_id'] = $r['vendor_id'] !== null ? (int) $r['vendor_id'] : null;
        $r['day'] = substr((string) $r['day'], 0, 10);

        return $r;
    }
}
