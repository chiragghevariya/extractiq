<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentEvent;
use App\Support\Pii;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail (every pipeline step and every human action on a document) as CSV, for auditors.
 * Admin only, at most a year per file, bank details masked, cells that a spreadsheet would run as
 * a formula are neutralised.
 */
class AuditLogController extends Controller
{
    public const COLUMNS = ['occurred_at', 'document_id', 'document_uuid', 'filename', 'document_number', 'step', 'status', 'from_status', 'to_status',
        'user', 'user_email', 'duration_ms', 'attempt', 'error', 'changes'];

    public function export(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'document_id' => 'nullable|integer',
        ]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $to->subDays(29)->startOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Export at most one year at a time.');

        $query = DocumentEvent::query() // tenant scope: this company only
            ->with(['document' => fn ($q) => $q->withTrashed()->select('id', 'uuid', 'original_filename', 'document_number'), 'user:id,name,email'])
            ->whereBetween('created_at', [$from, $to])
            ->when($data['document_id'] ?? null, fn ($q, $id) => $q->where('document_id', $id)); // streamed in id order (lazyById)

        Log::info('Audit log exported', ['user_id' => $request->user()->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $name = "extractiq-audit-log-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS, escape: '\\');
            foreach ($query->lazyById(500) as $e) {
                /** @var DocumentEvent $e */
                fputcsv($out, array_map(self::cell(...), [
                    $e->created_at?->toIso8601String(), $e->document_id, $e->document?->uuid, $e->document?->original_filename, $e->document?->document_number,
                    $e->step, $e->status, $e->from_status, $e->to_status, $e->user->name ?? ($e->user_id ? "user {$e->user_id}" : 'system'), $e->user?->email,
                    $e->duration_ms, $e->attempt, $e->error !== null ? Pii::mask($e->error) : null, self::changes($e->meta ?? []),
                ]), escape: '\\');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "total: 100.00 → 120.00; po_number: — → PO-1005" from the event's diff, masked. */
    private static function changes(array $meta): ?string
    {
        $parts = [];
        foreach ((array) data_get($meta, 'diff.fields', []) as $field => $d) {
            $fmt = fn ($v) => $v === null || $v === '' ? '—' : (is_scalar($v) ? (string) $v : json_encode($v));
            $parts[] = "$field: {$fmt($d['before'] ?? null)} → {$fmt($d['after'] ?? null)}";
        }
        foreach (['reason', 'note'] as $k) {
            if (filled($meta[$k] ?? null) && is_string($meta[$k])) {
                $parts[] = "$k: {$meta[$k]}";
            }
        }

        return $parts ? Pii::mask(implode('; ', $parts)) : null;
    }

    /** CSV injection: a leading = + - @ (or tab/CR) would be evaluated by Excel/Sheets. */
    private static function cell(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) && ! is_numeric($v) ? "'".$v : $v;
    }
}
