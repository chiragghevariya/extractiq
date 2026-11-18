<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\ErpConnection;
use App\Models\Vendor;
use App\Services\Analytics\AnalyticsReport;
use App\Services\Analytics\PipelineHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /analytics?from=&to=&group_by=day|week|month&vendor=&type=&connector=
 * GET /analytics/export (same filters) — the time series as CSV.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsReport $report, private readonly PipelineHealth $health) {}

    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        $f = $this->filters($request);

        return response()->json([
            'data' => $this->report->build($companyId, $f) + ['health' => $this->health->snapshot($companyId)],
            'meta' => [
                'filters' => array_filter([
                    'vendor' => $f['vendor'] ? (string) $f['vendor'] : null, 'type' => $f['type'], 'connector' => $f['connector'],
                ]),
                'options' => [
                    'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['id' => (string) $v->id, 'name' => $v->name]),
                    'types' => array_map(fn ($t) => $t->value, DocumentType::cases()),
                    'connectors' => ErpConnection::CONNECTORS,
                ],
                'status_groups' => AnalyticsReport::STATUS_GROUPS,
                'rollup' => ['today_live' => $f['to']->gte(CarbonImmutable::today()), 'nightly_at' => '01:15'],
            ],
        ]);
    }

    public function export(Request $request)
    {
        $f = $this->filters($request);
        $data = $this->report->build($request->user()->company_id, $f);
        $columns = ['period', 'received', 'processed', 'in_erp', 'in_progress', 'in_review', 'stopped', 'pushed', 'touchless', 'touchless_rate',
            'fields_total', 'fields_corrected', 'field_accuracy', 'avg_processing_minutes', 'llm_cost_usd', 'ocr_cost_usd', 'erp_attempts', 'erp_succeeded', 'erp_dead'];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns, escape: '\\');
        foreach ($data['series'] as $s) {
            fputcsv($out, [
                $s['period'], $s['received'], $s['processed'], $s['in_erp'], $s['in_progress'], $s['in_review'], $s['stopped'], $s['pushed'], $s['touchless'],
                $s['touchless_rate'], $s['fields_total'], $s['fields_corrected'], $s['field_accuracy'],
                $s['avg_processing_ms'] !== null ? round($s['avg_processing_ms'] / 60000, 2) : null,
                round($s['llm_cost_usd'], 6), round($s['ocr_cost_usd'], 6), $s['erp_attempts'], $s['erp_succeeded'], $s['erp_dead'],
            ], escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $name = "extractiq-analytics-{$f['from']->toDateString()}-to-{$f['to']->toDateString()}-by-{$f['group_by']}.csv";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$name\""]);
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, group_by: string, vendor: ?int, type: ?string, connector: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'group_by' => 'nullable|in:day,week,month',
            'vendor' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('company_id', $request->user()->company_id)],
            'type' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, DocumentType::cases()))],
            'connector' => ['nullable', Rule::in(ErpConnection::CONNECTORS)],
        ]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(29);
        if ($from->diffInDays($to) > 366) {
            abort(response()->json(['errors' => [['status' => '422', 'code' => 'range_too_long', 'title' => 'Pick a range of at most a year.', 'source' => ['pointer' => '/from']]]], 422));
        }
        $days = $from->diffInDays($to) + 1;

        return [
            'from' => $from, 'to' => $to,
            'group_by' => $data['group_by'] ?? ($days > 120 ? 'month' : ($days > 45 ? 'week' : 'day')),
            'vendor' => isset($data['vendor']) ? (int) $data['vendor'] : null, 'type' => $data['type'] ?? null, 'connector' => $data['connector'] ?? null,
        ];
    }
}
