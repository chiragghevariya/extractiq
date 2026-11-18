<?php

namespace App\Services\Erp;

use App\Models\MockErp\Bill;
use App\Models\MockErp\PurchaseOrder;
use App\Models\MockErp\Receipt;
use App\Models\MockErp\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** What a user does inside the Mock ERP: load demo data, post and pay bills, receive goods. */
class MockErp
{
    /** Load vendors, POs and receipts from samples/erp/seed.json (idempotent). @return array{vendors: int, purchase_orders: int, receipts: int} */
    public function seed(int $companyId, array $seed): array
    {
        return DB::transaction(function () use ($companyId, $seed) {
            foreach ($seed['vendors'] ?? [] as $v) {
                Vendor::withoutGlobalScopes()->updateOrCreate(['company_id' => $companyId, 'code' => $v['code']],
                    ['name' => $v['name'], 'tax_id' => $v['tax_id'] ?? null, 'email' => $v['email'] ?? null, 'currency' => $v['currency'] ?? 'USD', 'aliases' => $v['aliases'] ?? []]);
            }
            foreach ($seed['purchase_orders'] ?? [] as $p) {
                PurchaseOrder::withoutGlobalScopes()->updateOrCreate(['company_id' => $companyId, 'number' => $p['number']],
                    ['vendor_code' => $p['vendor_code'], 'order_date' => $p['order_date'] ?? null, 'currency' => $p['currency'], 'status' => 'open', 'lines' => $p['lines']]);
            }
            foreach ($seed['receipts'] ?? [] as $r) {
                Receipt::withoutGlobalScopes()->updateOrCreate(['company_id' => $companyId, 'number' => $r['number']],
                    ['po_number' => $r['po_number'], 'received_date' => $r['received_date'] ?? null, 'lines' => $r['lines']]);
            }

            return ['vendors' => count($seed['vendors'] ?? []), 'purchase_orders' => count($seed['purchase_orders'] ?? []), 'receipts' => count($seed['receipts'] ?? [])];
        });
    }

    public function post(Bill $bill): Bill
    {
        if ($bill->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Only draft bills can be posted (this one is {$bill->status})."]);
        }
        $bill->update(['status' => 'posted', 'posted_at' => now()]);

        return $bill;
    }

    public function pay(Bill $bill): Bill
    {
        if (! in_array($bill->status, ['draft', 'posted'], true)) {
            throw ValidationException::withMessages(['status' => "This bill is already {$bill->status}."]);
        }
        $bill->update(['status' => 'paid', 'posted_at' => $bill->posted_at ?? now(), 'paid_at' => now()]);

        return $bill;
    }

    /** Record a delivery of everything still outstanding on the PO (a new receipt). */
    public function receiveRemaining(PurchaseOrder $po): Receipt
    {
        $received = [];
        foreach (Receipt::withoutGlobalScopes()->where('company_id', $po->company_id)->where('po_number', $po->number)->get() as $r) {
            foreach ($r->lines as $l) {
                $received[$l['po_line_no'] ?? 0] = ($received[$l['po_line_no'] ?? 0] ?? 0) + (float) $l['quantity'];
            }
        }
        $lines = [];
        foreach ($po->lines as $l) {
            $open = (float) $l['quantity'] - ($received[$l['line_no']] ?? 0);
            if ($open > 0) {
                $lines[] = ['line_no' => count($lines) + 1, 'po_line_no' => (int) $l['line_no'], 'sku' => $l['sku'] ?? null, 'description' => $l['description'], 'quantity' => $open];
            }
        }
        if (! $lines) {
            throw ValidationException::withMessages(['po' => "Everything on {$po->number} has been received."]);
        }
        $n = Receipt::withoutGlobalScopes()->where('company_id', $po->company_id)->count() + 1;

        return Receipt::withoutGlobalScopes()->create([
            'company_id' => $po->company_id, 'number' => sprintf('MRC-%04d', $n), 'po_number' => $po->number, 'received_date' => now()->toDateString(), 'lines' => $lines,
        ]);
    }
}
