<?php

namespace App\Services\Analytics;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentMatch;
use App\Models\ErpPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds analytics_daily: one row per company × day × vendor × type × connector with additive
 * measures. Every measure is attributed to the day of its own event:
 *
 *   received, processed, status mix, LLM/OCR cost  → received_at
 *   pushed, touchless, processing time (→ pushed)  → pushed_at
 *   field accuracy, learned-hint accuracy, spend   → approved_at
 *   exception reasons                              → the current match's created_at
 *   ERP attempts / successes / dead-letters        → the attempt's created_at
 *
 * Definitions:
 *   processed  = finished OCR + extraction (anything past `extracted`, except OCR failures)
 *   touchless  = pushed with no human edit: no reviewer correction, accepted variance or vendor pick
 *   accuracy   = 1 − corrected fields / extracted fields, on approved documents; a corrected field is a
 *                header field a person changed, or a line item they changed, added or removed
 */
class AnalyticsRollup
{
    /** Processing-time buckets (upper bound in ms; null = open-ended). */
    public const BUCKETS = [
        'lt_1m' => ['Under 1 min', 60_000],
        '1m_5m' => ['1–5 min', 300_000],
        '5m_30m' => ['5–30 min', 1_800_000],
        '30m_2h' => ['30 min–2 h', 7_200_000],
        '2h_8h' => ['2–8 h', 28_800_000],
        '8h_24h' => ['8–24 h', 86_400_000],
        '1d_3d' => ['1–3 days', 259_200_000],
        'gt_3d' => ['Over 3 days', null],
    ];

    /** Events that mean a person changed what the machine produced. */
    private const HUMAN_EDITS = ['match_override', 'match_accept', 'vendor'];

    /** @return list<array<string, mixed>> rows for the days [from, to] (inclusive), not stored */
    public function compute(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $rows = [];
        $row = function &(string $day, ?int $vendor, string $type, ?string $connector) use (&$rows, $companyId): array {
            $key = "$day|$vendor|$type|$connector";
            if (! isset($rows[$key])) {
                $rows[$key] = ['company_id' => $companyId, 'day' => $day, 'vendor_id' => $vendor, 'type' => $type, 'connector' => $connector] + self::empty();
            }

            return $rows[$key];
        };
        // Tenant scope off (explicit company id), soft-delete scope kept: deleted documents don't count.
        $docs = fn () => Document::withoutGlobalScope('company')->where('company_id', $companyId);

        // 1. Arrivals: counts, status mix, cost — one SQL group-by.
        $arrivals = $docs()->whereBetween('received_at', [$start, $end])
            ->selectRaw('date(received_at) as d, vendor_id, type, erp_connector, status, ocr_provider, count(*) as n, sum(coalesce(llm_cost_usd, 0)) as llm, sum(coalesce(page_count, 1)) as pages')
            ->groupByRaw('date(received_at), vendor_id, type, erp_connector, status, ocr_provider')->toBase()->get();
        $prices = (array) config('extractiq.ocr_cost_per_page');
        foreach ($arrivals as $a) {
            $r = &$row(substr((string) $a->d, 0, 10), $a->vendor_id !== null ? (int) $a->vendor_id : null, (string) $a->type, $a->erp_connector);
            $status = (string) $a->status;
            $r['received'] += (int) $a->n;
            if (! in_array($status, ['received', 'ocr_done', 'extracted', 'failed'], true)) {
                $r['processed'] += (int) $a->n;
            }
            $r['status_counts'][$status] = ($r['status_counts'][$status] ?? 0) + (int) $a->n;
            $r['llm_cost_usd'] += (float) $a->llm;
            $price = $a->ocr_provider === 'textract'
                ? ($a->type === DocumentType::Invoice->value ? $prices['textract_expense'] : $prices['textract_document'])
                : ($prices[$a->ocr_provider] ?? 0.0);
            $r['ocr_cost_usd'] += (float) $price * (int) $a->pages;
            unset($r);
        }

        // 2. Pushes: processing time and touchless.
        $pushed = $docs()->whereBetween('pushed_at', [$start, $end])->get(['id', 'vendor_id', 'type', 'erp_connector', 'received_at', 'pushed_at']);
        $edited = $this->humanEdited($pushed->pluck('id')->all());
        foreach ($pushed as $d) {
            $r = &$row($d->pushed_at->toDateString(), $d->vendor_id, $d->type->value, $d->erp_connector);
            $ms = max(0, (int) $d->received_at->diffInMilliseconds($d->pushed_at));
            $r['pushed']++;
            $r['touchless'] += isset($edited[$d->id]) ? 0 : 1;
            $r['processing_ms_sum'] += $ms;
            $bucket = self::bucket($ms);
            $r['processing_hist'][$bucket] = ($r['processing_hist'][$bucket] ?? 0) + 1;
            unset($r);
        }

        // 3. Approvals: field accuracy (overall and for documents read with learned vendor hints) and spend.
        $approved = $docs()->whereBetween('approved_at', [$start, $end])->where('status', '!=', DocumentStatus::Rejected->value)
            ->get(['id', 'vendor_id', 'type', 'erp_connector', 'approved_at', 'extraction', 'currency', 'total_minor']);
        $corrections = $this->corrections($approved->pluck('id')->all());
        foreach ($approved as $d) {
            $r = &$row($d->approved_at->toDateString(), $d->vendor_id, $d->type->value, $d->erp_connector);
            $extracted = count(array_filter((array) ($d->extraction['fields'] ?? []), fn ($f) => (bool) ($f['has_value'] ?? false)));
            $corrected = $corrections[$d->id] ?? 0;
            $total = max($extracted, $corrected); // a field filled in by a person counts even if the model found nothing
            $r['fields_total'] += $total;
            $r['fields_corrected'] += $corrected;
            if (! empty(data_get($d->extraction, 'hints_used'))) {
                $r['hinted_docs']++;
                $r['hinted_fields_total'] += $total;
                $r['hinted_fields_corrected'] += $corrected;
            }
            if ($d->type === DocumentType::Invoice && $d->currency && $d->total_minor !== null) {
                $r['spend'][$d->currency] = ($r['spend'][$d->currency] ?? 0) + $d->total_minor;
            }
            unset($r);
        }

        // 4. Why invoices didn't match (current match per invoice).
        $matches = DocumentMatch::withoutGlobalScope('company')->with('lines:id,document_match_id,result', 'invoice:id,vendor_id,type,erp_connector')
            ->where('company_id', $companyId)->where('is_current', true)->whereIn('result', ['exception', 'accepted'])
            ->whereBetween('created_at', [$start, $end])->get();
        foreach ($matches as $m) {
            if (! $m->invoice) {
                continue;
            }
            $r = &$row($m->created_at->toDateString(), $m->invoice->vendor_id, $m->invoice->type->value, $m->invoice->erp_connector);
            $codes = array_merge(array_column($m->header_issues, 'code'), $m->lines->pluck('result')->reject(fn ($c) => $c === 'matched')->all());
            foreach ($codes as $code) {
                $r['exception_reasons'][$code] = ($r['exception_reasons'][$code] ?? 0) + 1;
            }
            unset($r);
        }

        // 5. ERP push attempts.
        $attempts = ErpPush::withoutGlobalScopes()->join('documents as d', 'd.id', '=', 'erp_pushes.document_id')
            ->where('erp_pushes.company_id', $companyId)->whereNull('d.deleted_at')->whereBetween('erp_pushes.created_at', [$start, $end])
            ->selectRaw('date(erp_pushes.created_at) as day, d.vendor_id, d.type, erp_pushes.connector, erp_pushes.status, count(*) as n')
            ->groupByRaw('date(erp_pushes.created_at), d.vendor_id, d.type, erp_pushes.connector, erp_pushes.status')->toBase()->get();
        foreach ($attempts as $a) {
            $r = &$row((string) $a->day, $a->vendor_id !== null ? (int) $a->vendor_id : null, (string) $a->type, $a->connector);
            $r['erp_attempts'] += (int) $a->n;
            $r['erp_succeeded'] += $a->status === 'succeeded' ? (int) $a->n : 0;
            $r['erp_dead'] += $a->status === 'dead' ? (int) $a->n : 0;
            unset($r);
        }

        return array_values($rows);
    }

    /** Recompute and store [from, to]. Idempotent: the days are replaced. @return int rows written */
    public function store(int $companyId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $rows = $this->compute($companyId, $from, $to);

        return DB::transaction(function () use ($companyId, $from, $to, $rows) {
            DB::table('analytics_daily')->where('company_id', $companyId)->whereBetween('day', [$from->toDateString(), $to->toDateString()])->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('analytics_daily')->insert(array_map(fn ($r) => self::encode($r), $chunk));
            }
            for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
                DB::table('analytics_rollup_days')->updateOrInsert(['company_id' => $companyId, 'day' => $d->toDateString()], ['computed_at' => now()]);
            }

            return count($rows);
        });
    }

    public static function empty(): array
    {
        return [
            'received' => 0, 'processed' => 0, 'status_counts' => [], 'llm_cost_usd' => 0.0, 'ocr_cost_usd' => 0.0,
            'pushed' => 0, 'touchless' => 0, 'processing_ms_sum' => 0, 'processing_hist' => [],
            'fields_total' => 0, 'fields_corrected' => 0, 'hinted_docs' => 0, 'hinted_fields_total' => 0, 'hinted_fields_corrected' => 0,
            'spend' => [], 'exception_reasons' => [], 'erp_attempts' => 0, 'erp_succeeded' => 0, 'erp_dead' => 0,
        ];
    }

    public static function bucket(int $ms): string
    {
        foreach (self::BUCKETS as $key => [, $max]) {
            if ($max === null || $ms < $max) {
                return $key;
            }
        }

        return 'gt_3d';
    }

    private static function encode(array $r): array
    {
        foreach (['status_counts', 'processing_hist', 'spend', 'exception_reasons'] as $k) {
            $r[$k] = $r[$k] ? json_encode($r[$k]) : null;
        }

        return $r;
    }

    /** @param list<int> $ids @return array<int, true> documents a person edited (fields, lines, variances, vendor) */
    private function humanEdited(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            DocumentEvent::withoutGlobalScopes()->whereIn('document_id', $chunk)->whereIn('step', self::HUMAN_EDITS)->pluck('document_id')
                ->each(function ($id) use (&$out) { $out[$id] = true; });
            foreach ($this->corrections($chunk) as $id => $n) {
                if ($n > 0) {
                    $out[$id] = true;
                }
            }
        }

        return $out;
    }

    /** @param list<int> $ids @return array<int, int> corrected fields per document, from reviewer diffs */
    private function corrections(array $ids): array
    {
        $fields = [];
        $lines = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DocumentEvent::withoutGlobalScopes()->whereIn('document_id', $chunk)->where('step', 'review')->get(['document_id', 'meta']) as $e) {
                foreach (array_keys((array) data_get($e->meta, 'diff.fields', [])) as $key) {
                    $fields[$e->document_id][$key] = true;
                }
                foreach ((array) data_get($e->meta, 'diff.lines', []) as $l) {
                    $lines[$e->document_id][(int) ($l['line'] ?? 0)] = true;
                }
            }
        }
        $out = [];
        foreach (array_unique([...array_keys($fields), ...array_keys($lines)]) as $id) {
            $out[$id] = count($fields[$id] ?? []) + count($lines[$id] ?? []);
        }

        return $out;
    }
}
