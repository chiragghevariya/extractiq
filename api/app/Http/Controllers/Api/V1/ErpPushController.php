<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ErpPush;
use Illuminate\Http\Request;

/** The push log: every attempt to create a bill in the ERP, with what was sent and what came back. */
class ErpPushController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'filter.status' => 'nullable|in:succeeded,failed,dead', 'filter.connector' => 'nullable|string|max:20',
            'filter.document' => 'nullable|integer', 'page.size' => 'nullable|integer|min:1|max:100',
        ]);
        $query = ErpPush::query()->with('document:id,original_filename,document_number,vendor_name,total_minor,currency,status,erp_url')->latest('id');
        foreach (['status', 'connector'] as $f) {
            if ($v = $request->input("filter.$f")) {
                $query->where($f, $v);
            }
        }
        if ($d = $request->input('filter.document')) {
            $query->where('document_id', $d);
        }
        $page = $query->paginate((int) $request->input('page.size', 25), ['*'], 'page[number]', (int) $request->input('page.number', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (ErpPush $p) => $this->resource($p, false)),
            'meta' => [
                'page' => ['current' => $page->currentPage(), 'size' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
                'summary' => [
                    'succeeded' => ErpPush::query()->where('status', 'succeeded')->count(),
                    'failed' => ErpPush::query()->where('status', 'failed')->count(),
                    'dead' => Document::query()->where('status', DocumentStatus::PushFailed->value)->count(),
                    'queued' => Document::query()->where('status', DocumentStatus::Matched->value)->count(),
                    'posted' => Document::query()->where('status', DocumentStatus::Posted->value)->count(),
                    'paid' => Document::query()->where('status', DocumentStatus::Paid->value)->count(),
                    'last_success_at' => ErpPush::query()->where('status', 'succeeded')->max('created_at'),
                ],
            ],
        ]);
    }

    /** One attempt with its full request and response (the viewer). */
    public function show(ErpPush $push)
    {
        return response()->json(['data' => $this->resource($push->load('document'), true)]);
    }

    private function resource(ErpPush $p, bool $full): array
    {
        $doc = $p->document;

        return [
            'type' => 'erp-pushes',
            'id' => (string) $p->id,
            'attributes' => array_filter([
                'connector' => $p->connector,
                'status' => $p->status,
                'attempt' => $p->attempt,
                'http_status' => $p->http_status,
                'duration_ms' => $p->duration_ms,
                'retryable' => $p->retryable,
                'external_ref' => $p->external_ref,
                'erp_url' => data_get($p->response, '_url'),
                'error' => $p->error,
                'idempotency_key' => $p->idempotency_key,
                'document' => $doc ? [
                    'id' => (string) $doc->id, 'number' => $doc->document_number, 'filename' => $doc->original_filename, 'vendor_name' => $doc->vendor_name,
                    'total_minor' => $doc->total_minor, 'currency' => $doc->currency, 'status' => $doc->status->value, 'erp_url' => $doc->erp_url,
                ] : null,
                'request' => $full ? $p->request : null,
                'response' => $full ? $p->response : null,
                'created_at' => $p->created_at?->toIso8601String(),
            ], fn ($v, $k) => $full || ! in_array($k, ['request', 'response'], true), ARRAY_FILTER_USE_BOTH),
        ];
    }
}
