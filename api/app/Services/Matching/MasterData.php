<?php

namespace App\Services\Matching;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * The one way master data gets written: from CSV imports, the ERP sync (Phase 6) and approved
 * PO / GRN documents. Upserts are keyed by normalised number, so re-importing is safe.
 *
 * Trust order: ERP and CSV are the systems of record. An extracted PO document never
 * overwrites a PO that came from them — it only links itself as the source document.
 */
class MasterData
{
    public function __construct(private readonly LineMatcher $lineMatcher, private readonly VendorResolver $vendors) {}

    /** @param array{name: string, tax_id?: ?string, email?: ?string, default_currency?: ?string, erp_ref?: ?string, aliases?: list<string>} $row */
    public function upsertVendor(int $companyId, array $row, string $source): Vendor
    {
        $existing = Vendor::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(function ($q) use ($row) {
                $q->where('normalized_name', Vendor::normalize($row['name']));
                if ($tax = Vendor::taxKey($row['tax_id'] ?? null)) {
                    $q->orWhere('tax_key', $tax);
                }
                if (! empty($row['erp_ref'])) {
                    $q->orWhere('erp_ref', $row['erp_ref']);
                }
            })->first();
        $vendor = $existing ?? new Vendor(['company_id' => $companyId, 'source' => $source]);
        $vendor->fill(array_filter([
            'name' => $row['name'],
            'tax_id' => $row['tax_id'] ?? null,
            'email' => $row['email'] ?? null,
            'default_currency' => isset($row['default_currency']) ? strtoupper($row['default_currency']) : null,
            'erp_ref' => $row['erp_ref'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $vendor->aliases = array_values(array_unique([...($vendor->aliases ?? []), ...($row['aliases'] ?? [])]));
        $vendor->company_id = $companyId;
        $vendor->save();

        return $vendor;
    }

    /**
     * @param  array{po_number: string, vendor_id?: ?int, order_date?: ?string, expected_date?: ?string, currency?: ?string, status?: ?string, erp_ref?: ?string, tax_minor?: ?int, total_minor?: ?int}  $header
     * @param  list<array{line_no?: ?int, sku?: ?string, description: string, quantity: float, uom?: ?string, unit_price_minor?: ?int, amount_minor?: ?int}>  $lines
     */
    public function upsertPurchaseOrder(int $companyId, array $header, array $lines, string $source, ?int $sourceDocumentId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $header, $lines, $source, $sourceDocumentId) {
            $po = PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($header['po_number']))->first();
            if ($po && $source === 'document' && in_array($po->source, ['erp', 'csv'], true)) {
                $po->update(['source_document_id' => $sourceDocumentId ?? $po->source_document_id]);

                return $po;
            }

            $subtotal = (int) array_sum(array_map(fn ($l) => $l['amount_minor'] ?? (int) round($l['quantity'] * ($l['unit_price_minor'] ?? 0)), $lines));
            $po ??= new PurchaseOrder(['company_id' => $companyId]);
            $po->fill([
                'po_number' => $header['po_number'],
                'vendor_id' => $header['vendor_id'] ?? $po->vendor_id,
                'order_date' => $header['order_date'] ?? $po->order_date,
                'expected_date' => $header['expected_date'] ?? $po->expected_date,
                'currency' => isset($header['currency']) ? strtoupper($header['currency']) : $po->currency,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $header['tax_minor'] ?? $po->tax_minor,
                'total_minor' => $header['total_minor'] ?? ($subtotal + (int) ($header['tax_minor'] ?? 0)),
                'status' => $header['status'] ?? $po->status ?? 'open',
                'source' => $source,
                'source_document_id' => $sourceDocumentId ?? $po->source_document_id,
                'erp_ref' => $header['erp_ref'] ?? $po->erp_ref,
            ]);
            $po->company_id = $companyId;
            $po->save();

            // Update lines in place by line number so goods-receipt links survive a re-import.
            $existing = PoLine::withoutGlobalScopes()->where('purchase_order_id', $po->id)->get()->keyBy('line_no');
            $seen = [];
            foreach (array_values($lines) as $i => $l) {
                $no = (int) ($l['line_no'] ?? $i + 1);
                $seen[] = $no;
                $line = $existing[$no] ?? new PoLine(['company_id' => $companyId, 'purchase_order_id' => $po->id, 'line_no' => $no]);
                $line->fill([
                    'sku' => $l['sku'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'], 'uom' => $l['uom'] ?? null,
                    'erp_ref' => $l['erp_ref'] ?? $line->erp_ref,
                    'unit_price_minor' => $l['unit_price_minor'] ?? null,
                    'amount_minor' => $l['amount_minor'] ?? (isset($l['unit_price_minor']) ? (int) round($l['quantity'] * $l['unit_price_minor']) : null),
                ])->save();
            }
            PoLine::withoutGlobalScopes()->where('purchase_order_id', $po->id)->whereNotIn('line_no', $seen)->delete();

            return $po->load('lines');
        });
    }

    /**
     * @param  array{grn_number: string, po_number?: ?string, received_date?: ?string, erp_ref?: ?string}  $header
     * @param  list<array{line_no?: ?int, po_line_no?: ?int, po_line_erp_ref?: ?string, sku?: ?string, description: string, quantity: float, uom?: ?string}>  $lines
     */
    public function upsertGoodsReceipt(int $companyId, array $header, array $lines, string $source, ?int $sourceDocumentId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($companyId, $header, $lines, $source, $sourceDocumentId) {
            $grn = GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($header['grn_number']))->first();
            if ($grn && $source === 'document' && in_array($grn->source, ['erp', 'csv'], true)) {
                $grn->update(['source_document_id' => $sourceDocumentId ?? $grn->source_document_id]);

                return $grn;
            }
            $po = ! empty($header['po_number'])
                ? PurchaseOrder::withoutGlobalScopes()->with('lines')->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($header['po_number']))->first()
                : null;

            $grn ??= new GoodsReceipt(['company_id' => $companyId]);
            $grn->fill([
                'grn_number' => $header['grn_number'], 'purchase_order_id' => $po?->id, 'vendor_id' => $po?->vendor_id,
                'received_date' => $header['received_date'] ?? $grn->received_date, 'source' => $source,
                'source_document_id' => $sourceDocumentId ?? $grn->source_document_id, 'erp_ref' => $header['erp_ref'] ?? $grn->erp_ref,
            ]);
            $grn->company_id = $companyId;
            $grn->save();

            GrnLine::withoutGlobalScopes()->where('goods_receipt_id', $grn->id)->delete();
            $objects = array_map(fn ($l) => (object) ($l + ['unit_price_minor' => null]), array_values($lines));
            $pairs = $po ? $this->lineMatcher->pair($objects, $po->lines) : [];
            foreach (array_values($lines) as $i => $l) {
                $explicit = ! $po ? null : (
                    (! empty($l['po_line_erp_ref']) ? $po->lines->firstWhere('erp_ref', $l['po_line_erp_ref']) : null)
                    ?? (isset($l['po_line_no']) ? $po->lines->firstWhere('line_no', (int) $l['po_line_no']) : null)
                );
                GrnLine::withoutGlobalScopes()->create([
                    'company_id' => $companyId, 'goods_receipt_id' => $grn->id, 'line_no' => (int) ($l['line_no'] ?? $i + 1),
                    'po_line_id' => ($explicit ?? $pairs[$i]['po'] ?? null)?->id,
                    'sku' => $l['sku'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'], 'uom' => $l['uom'] ?? null,
                ]);
            }

            return $grn->load('lines');
        });
    }

    /** An approved PO or GRN document becomes (or links to) master data. Returns the PO affected, if any. */
    public function syncDocument(Document $doc): ?PurchaseOrder
    {
        $doc->loadMissing('lines');
        $lines = $doc->lines->map(fn ($l) => [
            'line_no' => $l->line_no, 'sku' => $l->sku, 'description' => $l->description, 'quantity' => (float) $l->quantity,
            'uom' => $l->uom, 'unit_price_minor' => $l->unit_price_minor, 'amount_minor' => $l->amount_minor,
        ])->all();

        if ($doc->type === DocumentType::PurchaseOrder && $doc->document_number) {
            if (! $doc->vendor_id) {
                $this->vendors->apply($doc);
                $doc->save();
            }

            return $this->upsertPurchaseOrder($doc->company_id, [
                'po_number' => $doc->document_number, 'vendor_id' => $doc->vendor_id, 'order_date' => $doc->document_date?->toDateString(),
                'currency' => $doc->currency, 'tax_minor' => $doc->tax_minor, 'total_minor' => $doc->total_minor,
            ], $lines, 'document', $doc->id);
        }
        if ($doc->type === DocumentType::GoodsReceipt && $doc->document_number) {
            return $this->upsertGoodsReceipt($doc->company_id, [
                'grn_number' => $doc->document_number, 'po_number' => $doc->po_number, 'received_date' => $doc->document_date?->toDateString(),
            ], $lines, 'document', $doc->id)->purchaseOrder;
        }

        return null;
    }
}
