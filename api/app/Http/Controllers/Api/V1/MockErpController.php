<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MockErp\Bill;
use App\Models\MockErp\PurchaseOrder;
use App\Models\MockErp\Receipt;
use App\Models\MockErp\Vendor;
use App\Services\Erp\MockErp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** The Mock ERP's own screens: what an AP clerk would see inside the ERP. */
class MockErpController extends Controller
{
    public function __construct(private readonly MockErp $mock) {}

    public function bills(Request $request)
    {
        $request->validate(['filter.status' => 'nullable|in:draft,posted,paid,cancelled']);
        $q = Bill::query()->latest('id');
        if ($s = $request->input('filter.status')) {
            $q->where('status', $s);
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map(fn (Bill $b) => $this->bill($b, false)),
            'meta' => ['counts' => Bill::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')],
        ]);
    }

    public function bill(Bill|int|string $bill, bool $full = true): array
    {
        $b = $bill instanceof Bill ? $bill : Bill::query()->findOrFail($bill);

        return array_filter([
            'id' => (string) $b->id, 'ref' => $b->ref, 'vendor_code' => $b->vendor_code, 'vendor_name' => $b->vendor_name, 'bill_number' => $b->bill_number,
            'po_number' => $b->po_number, 'bill_date' => $b->bill_date?->toDateString(), 'due_date' => $b->due_date?->toDateString(), 'currency' => $b->currency,
            'subtotal_minor' => $b->subtotal_minor, 'tax_minor' => $b->tax_minor, 'total_minor' => $b->total_minor, 'status' => $b->status,
            'posted_at' => $b->posted_at?->toIso8601String(), 'paid_at' => $b->paid_at?->toIso8601String(),
            'attachment_name' => $b->attachment_name, 'created_at' => $b->created_at?->toIso8601String(),
            'lines' => $full ? $b->lines : null, 'fields' => $full ? $b->fields : null, 'idempotency_key' => $full ? $b->idempotency_key : null,
        ], fn ($v) => $v !== null);
    }

    public function showBill(Bill $bill)
    {
        return response()->json(['data' => $this->bill($bill)]);
    }

    public function postBill(Bill $bill)
    {
        return response()->json(['data' => $this->bill($this->mock->post($bill))]);
    }

    public function payBill(Bill $bill)
    {
        return response()->json(['data' => $this->bill($this->mock->pay($bill))]);
    }

    public function attachment(Bill $bill)
    {
        abort_unless((bool) $bill->attachment_path, 404);

        return Storage::disk((string) $bill->attachment_disk)->response($bill->attachment_path, $bill->attachment_name);
    }

    public function purchaseOrders()
    {
        $receipts = Receipt::query()->get()->groupBy('po_number');
        $vendors = Vendor::query()->pluck('name', 'code');

        return response()->json(['data' => PurchaseOrder::query()->orderBy('number')->get()->map(function (PurchaseOrder $po) use ($receipts, $vendors) {
            $got = [];
            foreach ($receipts->get($po->number, collect()) as $r) {
                foreach ($r->lines as $l) {
                    $got[$l['po_line_no'] ?? 0] = ($got[$l['po_line_no'] ?? 0] ?? 0) + (float) $l['quantity'];
                }
            }
            $lines = array_map(fn ($l) => $l + ['received' => $got[$l['line_no']] ?? 0], $po->lines);

            return [
                'id' => (string) $po->id, 'number' => $po->number, 'vendor_code' => $po->vendor_code, 'vendor_name' => $vendors[$po->vendor_code] ?? null,
                'order_date' => $po->order_date?->toDateString(), 'currency' => $po->currency, 'status' => $po->status, 'lines' => $lines,
                'receipts' => $receipts->get($po->number, collect())->map(fn ($r) => ['number' => $r->number, 'received_date' => $r->received_date?->toDateString()])->values(),
                'fully_received' => collect($lines)->every(fn ($l) => $l['received'] >= $l['quantity']),
            ];
        })]);
    }

    public function receive(PurchaseOrder $purchaseOrder)
    {
        $r = $this->mock->receiveRemaining($purchaseOrder);

        return response()->json(['data' => ['number' => $r->number, 'po_number' => $r->po_number, 'lines' => $r->lines]], 201);
    }

    public function vendors()
    {
        return response()->json(['data' => Vendor::query()->orderBy('code')->get()->map(fn (Vendor $v) => [
            'id' => (string) $v->id, 'code' => $v->code, 'name' => $v->name, 'tax_id' => $v->tax_id, 'currency' => $v->currency, 'aliases' => $v->aliases ?? [],
        ])]);
    }
}
