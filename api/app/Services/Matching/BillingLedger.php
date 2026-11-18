<?php

namespace App\Services\Matching;

use App\Enums\DocumentStatus;
use App\Models\GrnLine;
use App\Models\MatchLine;
use Illuminate\Support\Facades\DB;

/**
 * Quantities already received and already billed per PO line — the inputs to the 3-way
 * check and the over-billing guard (Σ invoiced across invoices ≤ received).
 *
 * "Billed" counts the current match of every other invoice whose match is matched or
 * accepted. Invoices still in exception don't consume quantity: otherwise two disputed
 * invoices would block each other.
 */
class BillingLedger
{
    /** @param list<int> $poLineIds @return array<int, float> */
    public function received(array $poLineIds): array
    {
        if (! $poLineIds) {
            return [];
        }

        return GrnLine::withoutGlobalScopes()->whereIn('po_line_id', $poLineIds)
            ->groupBy('po_line_id')->select('po_line_id', DB::raw('sum(quantity) as qty'))
            ->pluck('qty', 'po_line_id')->map(fn ($q) => (float) $q)->all();
    }

    /** @param list<int> $poLineIds @return array<int, float> */
    public function billed(array $poLineIds, ?int $exceptInvoiceId = null): array
    {
        if (! $poLineIds) {
            return [];
        }

        return MatchLine::withoutGlobalScopes()
            ->join('document_matches as dm', 'dm.id', '=', 'match_lines.document_match_id')
            ->join('documents as d', 'd.id', '=', 'dm.invoice_id')
            ->whereIn('match_lines.po_line_id', $poLineIds)
            ->where('dm.is_current', true)
            ->whereIn('dm.result', ['matched', 'accepted'])
            ->whereNull('d.deleted_at')
            ->where('d.status', '!=', DocumentStatus::Rejected->value)
            ->when($exceptInvoiceId, fn ($q) => $q->where('dm.invoice_id', '!=', $exceptInvoiceId))
            ->groupBy('match_lines.po_line_id')
            ->select('match_lines.po_line_id', DB::raw('sum(match_lines.invoiced_qty) as qty'))
            ->pluck('qty', 'po_line_id')->map(fn ($q) => (float) $q)->all();
    }

    /** Value already billed against a PO (qty × PO price), for finding a PO by remaining amount. */
    public function billedValue(int $purchaseOrderId, ?int $exceptInvoiceId = null): int
    {
        $lines = \App\Models\PoLine::withoutGlobalScopes()->where('purchase_order_id', $purchaseOrderId)->get(['id', 'unit_price_minor']);
        $billed = $this->billed($lines->pluck('id')->all(), $exceptInvoiceId);

        return (int) round($lines->sum(fn ($l) => ($billed[$l->id] ?? 0) * ($l->unit_price_minor ?? 0)));
    }
}
