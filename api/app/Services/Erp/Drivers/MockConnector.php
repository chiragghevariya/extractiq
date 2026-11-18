<?php

namespace App\Services\Erp\Drivers;

use App\Models\MockErp\Bill;
use App\Models\MockErp\PurchaseOrder;
use App\Models\MockErp\Receipt;
use App\Models\MockErp\Vendor;
use App\Services\Erp\BillStatus;
use App\Services\Erp\ErpBill;
use App\Services\Erp\ErpConnector;
use App\Services\Erp\ErpContext;
use App\Services\Erp\ErpException;
use App\Services\Erp\PushResult;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The in-app fake ERP: its own vendors, POs, receipts and bills (mock_erp_* tables), with
 * screens under /mock-erp to post and pay bills or record deliveries. Behaves like a real
 * connector — idempotent per key, can be put into an outage to demo retries and dead-lettering.
 */
class MockConnector implements ErpConnector
{
    public function __construct(private readonly array $config = [], private readonly ?ErpContext $ctx = null) {}

    public function name(): string
    {
        return 'mock';
    }

    public function capabilities(): array
    {
        return ['sync_vendors' => true, 'sync_purchase_orders' => true, 'sync_receipts' => true, 'bill_status' => true, 'attachments' => true];
    }

    public function testConnection(): array
    {
        return $this->down()
            ? ['ok' => false, 'message' => 'Mock ERP is in a simulated outage (switch it off on the Mock ERP screen).']
            : ['ok' => true, 'message' => sprintf('Mock ERP reachable: %d vendors, %d purchase orders, %d bills.',
                Vendor::withoutGlobalScopes()->where('company_id', $this->company())->count(),
                PurchaseOrder::withoutGlobalScopes()->where('company_id', $this->company())->count(),
                Bill::withoutGlobalScopes()->where('company_id', $this->company())->count())];
    }

    public function syncVendors(): array
    {
        $this->guard();

        return Vendor::withoutGlobalScopes()->where('company_id', $this->company())->orderBy('code')->get()->map(fn (Vendor $v) => [
            'name' => $v->name, 'tax_id' => $v->tax_id, 'email' => $v->email, 'default_currency' => $v->currency,
            'erp_ref' => "mock:{$v->code}", 'aliases' => $v->aliases ?? [],
        ])->all();
    }

    public function syncPurchaseOrders(): array
    {
        $this->guard();

        return PurchaseOrder::withoutGlobalScopes()->where('company_id', $this->company())->orderBy('number')->get()->map(fn (PurchaseOrder $po) => [
            'header' => [
                'po_number' => $po->number, 'vendor_erp_ref' => "mock:{$po->vendor_code}", 'order_date' => $po->order_date?->toDateString(),
                'currency' => $po->currency, 'status' => $po->status, 'erp_ref' => "mock:{$po->number}",
            ],
            'lines' => array_map(fn ($l) => [
                'line_no' => (int) $l['line_no'], 'sku' => $l['sku'] ?? null, 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
                'uom' => $l['uom'] ?? null, 'unit_price_minor' => Money::toMinor($l['unit_price'], $po->currency), 'erp_ref' => "mock:{$po->number}:{$l['line_no']}",
            ], $po->lines),
        ])->all();
    }

    public function syncReceipts(): array
    {
        $this->guard();

        return Receipt::withoutGlobalScopes()->where('company_id', $this->company())->orderBy('number')->get()->map(fn (Receipt $r) => [
            'header' => ['grn_number' => $r->number, 'po_number' => $r->po_number, 'received_date' => $r->received_date?->toDateString(), 'erp_ref' => "mock:{$r->number}"],
            'lines' => array_map(fn ($l) => [
                'line_no' => (int) $l['line_no'], 'po_line_erp_ref' => isset($l['po_line_no']) ? "mock:{$r->po_number}:{$l['po_line_no']}" : null,
                'po_line_no' => $l['po_line_no'] ?? null, 'sku' => $l['sku'] ?? null, 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
            ], $r->lines),
        ])->all();
    }

    public function pushVendorBill(ErpBill $bill): PushResult
    {
        $request = $bill->toArray();
        $existing = Bill::withoutGlobalScopes()->where('company_id', $this->company())->where('idempotency_key', $bill->idempotencyKey)->first();
        if ($existing) {
            return new PushResult($existing->ref, ['status' => 'exists', 'id' => $existing->id, 'ref' => $existing->ref], alreadyExisted: true,
                url: $this->url($existing), request: $request, httpStatus: 200, state: $existing->status);
        }
        $this->guard($request);
        $failEvery = (int) ($this->config['fail_every'] ?? 0);
        if ($failEvery > 0 && Cache::increment('erp-mock:counter') % $failEvery === 0) {
            throw new ErpException('Mock ERP: 503 Service Unavailable.', request: $request, httpStatus: 503, response: ['error' => 'service_unavailable']);
        }
        if ($bill->totalMinor <= 0) {
            throw new ErpException('Mock ERP: a bill must have a positive total.', retryable: false, request: $request, httpStatus: 422,
                response: ['error' => 'validation', 'field' => 'total']);
        }

        $vendor = $this->vendorFor($bill);
        $created = DB::transaction(function () use ($bill, $vendor) {
            $b = Bill::withoutGlobalScopes()->create([
                'company_id' => $this->company(), 'ref' => 'pending-'.uniqid(), 'idempotency_key' => $bill->idempotencyKey,
                'vendor_code' => $vendor->code, 'vendor_name' => $vendor->name,
                'bill_number' => $bill->fields['bill_number'] ?? $bill->fields['reference'] ?? $bill->number,
                'po_number' => $bill->fields['po_number'] ?? null, 'bill_date' => $bill->fields['bill_date'] ?? $bill->date,
                'due_date' => $bill->fields['due_date'] ?? $bill->dueDate, 'currency' => $bill->currency,
                'subtotal_minor' => $bill->subtotalMinor, 'tax_minor' => $bill->taxMinor, 'total_minor' => $bill->totalMinor,
                'lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['line_no', 'sku', 'description', 'quantity', 'uom', 'unit_price', 'amount', 'tax_rate', 'account', 'tax_code', 'cost_centre', 'po_line_ref'])), $bill->lines),
                'fields' => $bill->fields, 'status' => 'draft',
            ]);
            $b->ref = sprintf('BILL-%05d', $b->id);
            if ($contents = $bill->attachmentContents()) {
                $path = "mock-erp/{$this->company()}/{$b->ref}/".basename((string) $bill->attachment['filename']);
                Storage::disk($bill->attachment['disk'])->put($path, $contents);
                $b->fill(['attachment_name' => $bill->attachment['filename'], 'attachment_path' => $path, 'attachment_disk' => $bill->attachment['disk']]);
            }
            $b->save();

            return $b;
        });

        return new PushResult($created->ref, ['status' => 'created', 'id' => $created->id, 'ref' => $created->ref, 'vendor_code' => $created->vendor_code, 'attachment' => (bool) $created->attachment_path],
            url: $this->url($created), request: $request, httpStatus: 201, state: 'draft');
    }

    public function getBillStatus(string $ref): ?BillStatus
    {
        $this->guard();
        $b = Bill::withoutGlobalScopes()->where('company_id', $this->company())->where('ref', $ref)->first();

        return $b ? new BillStatus($b->status, $b->paid_at) : null;
    }

    private function vendorFor(ErpBill $bill): Vendor
    {
        $q = fn () => Vendor::withoutGlobalScopes()->where('company_id', $this->company());
        if ($bill->vendorRef && str_starts_with($bill->vendorRef, 'mock:')) {
            if ($v = $q()->where('code', substr($bill->vendorRef, 5))->first()) {
                return $v;
            }
        }
        $v = ($bill->vendorTaxId ? $q()->where('tax_id', $bill->vendorTaxId)->first() : null) ?? $q()->whereRaw('lower(name) = ?', [mb_strtolower($bill->vendorName)])->first();
        if ($v) {
            return $v;
        }

        // Like most ERPs' "create vendor on the fly".
        return Vendor::withoutGlobalScopes()->create([
            'company_id' => $this->company(), 'code' => sprintf('V-%03d', $q()->count() + 900), 'name' => $bill->vendorName,
            'tax_id' => $bill->vendorTaxId, 'currency' => $bill->currency,
        ]);
    }

    private function url(Bill $b): string
    {
        return rtrim((string) config('extractiq.web_url'), '/')."/mock-erp?bill={$b->id}";
    }

    private function down(): bool
    {
        return (bool) $this->ctx?->setting('outage', false);
    }

    private function guard(array $request = []): void
    {
        if ($this->down()) {
            throw new ErpException('Mock ERP: 503 Service Unavailable (simulated outage).', request: $request, httpStatus: 503, response: ['error' => 'service_unavailable']);
        }
    }

    private function company(): int
    {
        return $this->ctx ? $this->ctx->companyId : 0;
    }
}
