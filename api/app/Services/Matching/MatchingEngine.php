<?php

namespace App\Services\Matching;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentMatch;
use App\Models\MatchLine;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Matches an invoice against master data, line by line.
 *
 * 2-way: invoice vs PO (price, and quantity against what was ordered).
 * 3-way: also against what was received on goods receipts, with the over-billing guard —
 *        quantity invoiced across all invoices may not exceed quantity received.
 *
 * Each invoice line gets one result, worst first:
 *   not_on_po > not_received > qty_variance > price_variance > matched
 * The header is matched only when every line is matched (or accepted) and there are no header
 * issues (PO not found or closed, vendor or currency mismatch, total outside tolerance).
 */
class MatchingEngine
{
    private const EPS = 0.0005;

    public function __construct(
        private readonly PurchaseOrderLocator $locator,
        private readonly LineMatcher $lineMatcher,
        private readonly BillingLedger $ledger,
    ) {}

    public function match(Document $invoice): DocumentMatch
    {
        $invoice->loadMissing('lines', 'company');
        $settings = MatchSettings::for($invoice->company);
        $currency = $invoice->currency;
        $header = [];
        $issue = function (string $code, string $message, array $extra = []) use (&$header) {
            $header[] = ['code' => $code, 'message' => $message] + $extra;
        };

        $found = $this->locator->locate($invoice);
        /** @var ?PurchaseOrder $po */
        $po = $found['po'];
        $receipts = $po ? $po->receipts()->withoutGlobalScopes()->get() : collect();
        $kind = match ($settings->mode) {
            'two_way' => 'two_way',
            'three_way' => 'three_way',
            default => $receipts->isNotEmpty() ? 'three_way' : 'two_way',
        };

        if (! $po) {
            $issue('po_not_found', $invoice->po_number
                ? "No purchase order {$invoice->po_number} was found."
                : 'The invoice has no PO number and no open PO for this vendor fits it.');
        } else {
            $po->loadMissing('lines');
            if (! $po->isOpen()) {
                $issue('po_closed', "Purchase order {$po->po_number} is {$po->status}.");
            }
            if ($po->vendor_id && $invoice->vendor_id && $po->vendor_id !== $invoice->vendor_id) {
                $issue('vendor_mismatch', 'The invoice vendor differs from the PO vendor.');
            }
            if ($po->currency && $currency && $po->currency !== $currency) {
                $issue('currency_mismatch', "Invoice currency {$currency} differs from PO currency {$po->currency}.");
            }
        }

        $lines = $invoice->lines->sortBy('line_no')->values();
        $pairs = $po ? $this->lineMatcher->pair($lines->all(), $po->lines) : array_fill(0, $lines->count(), ['po' => null, 'method' => 'none', 'score' => null]);
        $poLineIds = $po ? $po->lines->pluck('id')->all() : [];
        $received = $this->ledger->received($poLineIds);
        $billedBefore = $this->ledger->billed($poLineIds, $invoice->id);
        $billedHere = [];

        $rows = [];
        $expected = 0;
        $flagged = 0; // differences already reported on lines (price variances, lines not on the PO)
        foreach ($lines as $i => $line) {
            /** @var DocumentLine $line */
            $poLine = $pairs[$i]['po'];
            $qty = (float) $line->quantity;
            $amount = $line->amount_minor ?? ($line->unit_price_minor !== null ? (int) round($qty * $line->unit_price_minor) : 0);
            $row = [
                'invoice_line_id' => $line->id, 'po_line_id' => $poLine?->id, 'line_no' => $line->line_no, 'sku' => $line->sku,
                'description' => $line->description, 'method' => $pairs[$i]['method'], 'similarity' => $pairs[$i]['score'],
                'invoiced_qty' => $qty, 'ordered_qty' => $poLine?->quantity, 'received_qty' => null, 'previously_invoiced_qty' => 0,
                'invoice_price_minor' => $line->unit_price_minor, 'po_price_minor' => $poLine?->unit_price_minor, 'invoice_amount_minor' => $amount,
            ];

            if (! $poLine) {
                $flagged += $amount; // not expected by the PO at all — already reported on the line
                $rows[] = $row + ['issues' => ['not_on_po'], 'result' => 'not_on_po', 'disputed_minor' => max(0, $amount),
                    'message' => "Line {$line->line_no} ({$line->description}) is not on ".($po ? $po->po_number : 'a PO').'.'];

                continue;
            }

            $issues = [];
            $messages = [];
            $disputed = 0;
            $before = $billedBefore[$poLine->id] ?? 0.0;
            $cumulative = $before + ($billedHere[$poLine->id] ?? 0.0) + $qty;
            $billedHere[$poLine->id] = ($billedHere[$poLine->id] ?? 0.0) + $qty;
            $row['previously_invoiced_qty'] = $before + ($billedHere[$poLine->id] - $qty);
            $poPrice = $poLine->unit_price_minor;
            $price = $line->unit_price_minor ?? $poPrice;
            $expected += $poPrice !== null ? (int) round($qty * $poPrice) : $amount;

            if ($kind === 'three_way') {
                $got = $received[$poLine->id] ?? 0.0;
                $row['received_qty'] = $got;
                if ($got <= self::EPS) {
                    $issues[] = 'not_received';
                    $messages[] = "Line {$line->line_no}: nothing has been received for this PO line yet.";
                    $disputed = max($disputed, $amount);
                } elseif ($cumulative > $settings->qtyLimit($got) + self::EPS) {
                    $over = min($qty, $cumulative - $settings->qtyLimit($got));
                    $issues[] = 'qty_variance';
                    $messages[] = sprintf('Line %d: %s invoiced%s but only %s received.', $line->line_no, $this->qty($cumulative),
                        $row['previously_invoiced_qty'] > 0 ? ' in total (incl. '.$this->qty($row['previously_invoiced_qty']).' on earlier invoices)' : '', $this->qty($got));
                    $disputed = max($disputed, (int) round($over * ($price ?? 0)));
                }
            }
            if (! in_array('qty_variance', $issues, true) && $cumulative > $settings->qtyLimit($poLine->quantity) + self::EPS) {
                $over = min($qty, $cumulative - $settings->qtyLimit($poLine->quantity));
                $issues[] = 'qty_variance';
                $messages[] = sprintf('Line %d: %s invoiced%s against %s ordered.', $line->line_no, $this->qty($cumulative),
                    $row['previously_invoiced_qty'] > 0 ? ' in total (incl. '.$this->qty($row['previously_invoiced_qty']).' on earlier invoices)' : '', $this->qty($poLine->quantity));
                $disputed = max($disputed, (int) round($over * ($price ?? 0)));
            }
            if ($line->unit_price_minor !== null && $poPrice !== null) {
                $delta = $line->unit_price_minor - $poPrice;
                if (abs($delta) > $settings->priceAllowance($poPrice, $currency)) {
                    $issues[] = 'price_variance';
                    $messages[] = sprintf('Line %d: unit price %s vs PO %s (%s%.1f%%).', $line->line_no, Money::format($line->unit_price_minor, $currency),
                        Money::format($poPrice, $po->currency ?? $currency), $delta > 0 ? '+' : '', $poPrice ? $delta / $poPrice * 100 : 0);
                    $disputed += max(0, (int) round($delta * $qty));
                    $flagged += (int) round($delta * $qty);
                }
            }

            $order = ['not_received', 'qty_variance', 'price_variance'];
            usort($issues, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            $rows[] = $row + ['issues' => $issues, 'result' => $issues[0] ?? 'matched', 'disputed_minor' => min($disputed, max(0, $amount)),
                'message' => implode(' ', $messages)];
        }

        // Header value check: what the invoice charges (net) vs. what the PO says these lines cost. Price
        // variances already flagged on lines are taken out, so what's left is drift that slipped under the
        // per-line tolerance plus charges that appear only in the subtotal.
        $net = $invoice->subtotal_minor ?? (int) $lines->sum(fn ($l) => $l->amount_minor ?? 0);
        $delta = $net - $expected;
        $unexplained = $delta - $flagged;
        if ($po && abs($unexplained) > $settings->totalAllowance($currency)) {
            $issue('total_variance', sprintf('Invoice net %s is %s%s off what PO prices give for these lines (%s), beyond line-level differences.',
                Money::format($net, $currency), $unexplained > 0 ? '+' : '−', Money::format(abs($unexplained), $currency), Money::format($expected, $currency)),
                ['expected' => $expected + $flagged, 'actual' => $net, 'delta' => $unexplained]);
        }

        $disputed = (int) array_sum(array_column($rows, 'disputed_minor')) + max(0, $po && abs($unexplained) > $settings->totalAllowance($currency) ? $unexplained : 0);
        $ok = ! $header && collect($rows)->every(fn ($r) => $r['result'] === 'matched');

        return DB::transaction(function () use ($invoice, $po, $found, $kind, $header, $rows, $net, $expected, $delta, $disputed, $receipts, $settings, $ok) {
            DocumentMatch::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('is_current', true)->update(['is_current' => false]);
            $match = DocumentMatch::withoutGlobalScopes()->create([
                'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'purchase_order_id' => $po?->id,
                'kind' => $kind, 'result' => $ok ? 'matched' : 'exception', 'is_current' => true,
                'po_method' => $found['method'], 'po_confidence' => $found['confidence'],
                'header_issues' => $header,
                'discrepancies' => array_merge($header, array_values(array_map(fn ($r) => [
                    'code' => $r['result'], 'message' => $r['message'], 'line_no' => $r['line_no'], 'sku' => $r['sku'], 'disputed_minor' => $r['disputed_minor'],
                ], array_filter($rows, fn ($r) => $r['result'] !== 'matched')))),
                'totals' => ['invoiced_minor' => $net, 'expected_minor' => $expected, 'delta_minor' => $delta, 'disputed_minor' => $disputed],
                'goods_receipt_ids' => $receipts->pluck('id')->all(),
                'tolerances' => $settings->toArray() + ['po_candidates' => $found['candidates']],
            ]);
            foreach ($rows as $r) {
                unset($r['message']);
                MatchLine::withoutGlobalScopes()->create($r + ['company_id' => $invoice->company_id, 'document_match_id' => $match->id]);
            }

            return $match->load('lines');
        });
    }

    private function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.');
    }
}
