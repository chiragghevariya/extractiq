<?php

namespace App\Services\Matching;

use App\Models\Document;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Finds the purchase order an invoice bills against.
 *
 *   1. By the PO number printed on the invoice ("po 1001", "PO-1001" and "PO#1001" are the same).
 *   2. No number (or no such PO): among the vendor's open POs in the same currency, score each on
 *      how close the invoice's net amount is to the PO's remaining (or full) value, and how many
 *      of the invoice's lines appear on it. Take the best one when it is clearly ahead.
 */
class PurchaseOrderLocator
{
    public const ACCEPT = 0.75;

    public const MARGIN = 0.1;

    public function __construct(private readonly LineMatcher $lines, private readonly BillingLedger $ledger) {}

    /** @return array{po: ?PurchaseOrder, method: ?string, confidence: ?float, candidates: list<array{id: string, number: string, score: float}>} */
    public function locate(Document $invoice): array
    {
        $none = ['po' => null, 'method' => null, 'confidence' => null, 'candidates' => []];

        if ($invoice->po_number && ($key = PurchaseOrder::key($invoice->po_number)) !== '') {
            $po = PurchaseOrder::withoutGlobalScopes()->with('lines')
                ->where('company_id', $invoice->company_id)->where('number_key', $key)->first();
            if ($po) {
                return ['po' => $po, 'method' => 'po_number', 'confidence' => 1.0, 'candidates' => []];
            }
        }
        if (! $invoice->vendor_id) {
            return $none;
        }

        $invoice->loadMissing('lines');
        $net = $invoice->subtotal_minor ?? ($invoice->total_minor !== null ? $invoice->total_minor - (int) $invoice->tax_minor - (int) $invoice->shipping_minor : null);
        $open = PurchaseOrder::withoutGlobalScopes()->with('lines')
            ->where('company_id', $invoice->company_id)->where('vendor_id', $invoice->vendor_id)->where('status', 'open')
            ->when($invoice->currency, fn ($q) => $q->where(fn ($w) => $w->whereNull('currency')->orWhere('currency', $invoice->currency)))
            ->get();

        /** @var Collection<int, array{po: PurchaseOrder, score: float}> $ranked */
        $ranked = $open->map(fn (PurchaseOrder $po) => ['po' => $po, 'score' => $this->score($invoice, $po, $net)])
            ->sortByDesc('score')->values();
        $candidates = $ranked->take(5)->map(fn ($r) => ['id' => (string) $r['po']->id, 'number' => $r['po']->po_number, 'score' => $r['score']])->all();
        $top = $ranked->first();
        $second = $ranked->get(1);
        if ($top && $top['score'] >= self::ACCEPT && (! $second || $top['score'] - $second['score'] >= self::MARGIN)) {
            return ['po' => $top['po'], 'method' => 'vendor_amount', 'confidence' => $top['score'], 'candidates' => $candidates];
        }

        return ['candidates' => $candidates] + $none;
    }

    private function score(Document $invoice, PurchaseOrder $po, ?int $net): float
    {
        $value = $po->subtotal_minor ?? (int) $po->lines->sum(fn ($l) => $l->amount_minor ?? (int) round($l->quantity * ($l->unit_price_minor ?? 0)));
        $remaining = $value - $this->ledger->billedValue($po->id, $invoice->id);
        $amount = 0.0;
        if ($net !== null && $net > 0) {
            foreach (array_filter([$remaining, $value], fn ($v) => $v > 0) as $target) {
                $amount = max($amount, 1 - min(1, abs($net - $target) / max($net, $target)));
            }
        }
        $lines = $invoice->lines->all();
        $paired = $lines ? count(array_filter($this->lines->pair($lines, $po->lines), fn ($p) => $p['po'] !== null)) / count($lines) : 0.0;

        return round(0.6 * $amount + 0.4 * $paired, 4);
    }
}
