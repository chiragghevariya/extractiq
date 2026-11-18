<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentMatch;
use App\Models\GrnLine;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Services\Matching\BillingLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Master-data purchase orders with their progress: per line, how much was ordered, received
 * (goods receipts) and billed (invoices whose match is matched or accepted).
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly BillingLedger $ledger) {}

    public function index(Request $request)
    {
        $request->validate([
            'filter.q' => 'nullable|string|max:100', 'filter.status' => 'nullable|in:open,closed,cancelled',
            'filter.source' => 'nullable|in:csv,erp,document,manual', 'filter.vendor' => 'nullable|integer', 'page.size' => 'nullable|integer|min:1|max:100',
        ]);
        $query = PurchaseOrder::query()->with('vendor:id,name', 'lines:id,purchase_order_id,quantity,unit_price_minor')->withCount('receipts')
            ->latest('order_date')->latest('id');
        if ($q = $request->input('filter.q')) {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(po_number) like ?', [$like])->orWhereHas('vendor', fn ($v) => $v->whereRaw('lower(name) like ?', [$like])));
        }
        foreach (['status', 'source'] as $f) {
            if ($v = $request->input("filter.$f")) {
                $query->where($f, $v);
            }
        }
        if ($v = $request->input('filter.vendor')) {
            $query->where('vendor_id', $v);
        }
        $page = $query->paginate((int) $request->input('page.size', 25), ['*'], 'page[number]', (int) $request->input('page.number', 1));

        $lineIds = $page->getCollection()->flatMap(fn (PurchaseOrder $po) => $po->lines->pluck('id'))->all();
        $received = $this->ledger->received($lineIds);
        $billed = $this->ledger->billed($lineIds);
        $exceptions = DocumentMatch::query()->where('is_current', true)->whereIn('purchase_order_id', $page->getCollection()->pluck('id'))
            ->whereHas('invoice', fn ($d) => $d->where('status', DocumentStatus::MatchException->value))
            ->selectRaw('purchase_order_id, count(*) as c')->groupBy('purchase_order_id')->pluck('c', 'purchase_order_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (PurchaseOrder $po) => $this->row($po, $received, $billed, (int) ($exceptions[$po->id] ?? 0)))->values(),
            'meta' => [
                'page' => ['current' => $page->currentPage(), 'size' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
                'counts' => PurchaseOrder::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            ],
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $po = $purchaseOrder->load('vendor:id,name', 'lines', 'receipts.lines', 'sourceDocument:id,original_filename');
        $ids = $po->lines->pluck('id')->all();
        $received = $this->ledger->received($ids);
        $billed = $this->ledger->billed($ids);
        $matches = DocumentMatch::query()->with('invoice:id,document_number,status,total_minor,currency,vendor_name,on_hold_at')
            ->where('purchase_order_id', $po->id)->where('is_current', true)->latest('id')->get();

        $row = $this->row($po, $received, $billed, $matches->filter(fn ($m) => $m->invoice?->status === DocumentStatus::MatchException)->count());
        $row['attributes'] += [
            'lines' => $po->lines->map(fn (PoLine $l) => [
                'id' => (string) $l->id, 'line_no' => $l->line_no, 'sku' => $l->sku, 'description' => $l->description, 'uom' => $l->uom,
                'quantity' => $l->quantity, 'unit_price_minor' => $l->unit_price_minor, 'amount_minor' => $l->amount_minor,
                'received_qty' => $received[$l->id] ?? 0.0, 'billed_qty' => $billed[$l->id] ?? 0.0,
            ])->values(),
            'receipts' => $po->receipts->map(fn ($g) => [
                'id' => (string) $g->id, 'number' => $g->grn_number, 'received_date' => $g->received_date?->toDateString(), 'source' => $g->source,
                'source_document_id' => $g->source_document_id ? (string) $g->source_document_id : null,
                'lines' => $g->lines->map(fn (GrnLine $gl) => ['line_no' => $gl->line_no, 'sku' => $gl->sku, 'description' => $gl->description, 'quantity' => $gl->quantity, 'po_line_id' => $gl->po_line_id ? (string) $gl->po_line_id : null])->values(),
            ])->values(),
            'invoices' => $matches->map(fn ($m) => [
                'id' => (string) $m->invoice_id, 'number' => $m->invoice?->document_number, 'status' => $m->invoice?->status->value,
                'total_minor' => $m->invoice?->total_minor, 'currency' => $m->invoice?->currency, 'match_result' => $m->result, 'on_hold' => $m->invoice?->on_hold_at !== null,
            ])->values(),
            'source_document' => $po->sourceDocument ? ['id' => (string) $po->sourceDocument->id, 'filename' => $po->sourceDocument->original_filename] : null,
        ];

        return response()->json(['data' => $row]);
    }

    /** @param array<int, float> $received @param array<int, float> $billed */
    private function row(PurchaseOrder $po, array $received, array $billed, int $exceptions): array
    {
        /** @var Collection<int, PoLine> $lines */
        $lines = $po->lines;
        $ordered = $lines->sum('quantity');
        $value = fn (array $qty) => (int) round($lines->sum(fn ($l) => ($qty[$l->id] ?? 0) * ($l->unit_price_minor ?? 0)));

        return ['type' => 'purchase-orders', 'id' => (string) $po->id, 'attributes' => [
            'number' => $po->po_number,
            'vendor' => $po->vendor ? ['id' => (string) $po->vendor->id, 'name' => $po->vendor->name] : null,
            'order_date' => $po->order_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'currency' => $po->currency,
            'subtotal_minor' => $po->subtotal_minor,
            'total_minor' => $po->total_minor,
            'status' => $po->status,
            'source' => $po->source,
            'erp_ref' => $po->erp_ref,
            'line_count' => $lines->count(),
            'receipt_count' => $po->receipts_count ?? $po->receipts()->count(),
            'received_pct' => $ordered > 0 ? round(min(1, array_sum(array_intersect_key($received, $lines->keyBy('id')->all())) / $ordered), 4) : 0,
            'billed_pct' => $ordered > 0 ? round(min(1, array_sum(array_intersect_key($billed, $lines->keyBy('id')->all())) / $ordered), 4) : 0,
            'received_minor' => $value($received),
            'billed_minor' => $value($billed),
            'exception_count' => $exceptions,
        ]];
    }
}
