<?php

namespace App\Services\Matching;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\MatchDocument;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\PurchaseOrder;

/**
 * When master data changes (a PO or receipt is imported, synced or approved), invoices stuck in
 * match_exception that could now match are matched again: those already pointing at the PO,
 * those printing its number, and those of the same vendor that found no PO at all.
 */
class Rematcher
{
    /** @param list<int> $poIds */
    public function forPurchaseOrders(int $companyId, array $poIds): int
    {
        $pos = PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $poIds)->get(['id', 'number_key', 'vendor_id']);
        if ($pos->isEmpty()) {
            return 0;
        }
        $waiting = Document::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('type', DocumentType::Invoice->value)->where('status', DocumentStatus::MatchException->value)
            ->get(['id', 'po_number', 'vendor_id']);
        $current = DocumentMatch::withoutGlobalScopes()->whereIn('invoice_id', $waiting->pluck('id'))->where('is_current', true)
            ->get(['invoice_id', 'purchase_order_id'])->keyBy('invoice_id');
        $keys = $pos->pluck('number_key')->all();
        $vendors = $pos->pluck('vendor_id')->filter()->all();

        $ids = $waiting->filter(function (Document $d) use ($current, $poIds, $keys, $vendors) {
            $m = $current->get($d->id);

            return ($m && in_array($m->purchase_order_id, $poIds, true))
                || ($d->po_number && in_array(PurchaseOrder::key($d->po_number), $keys, true))
                || ((! $m || ! $m->purchase_order_id) && in_array($d->vendor_id, $vendors, true));
        })->pluck('id');
        $ids->each(fn ($id) => MatchDocument::dispatch($id));

        return $ids->count();
    }
}
