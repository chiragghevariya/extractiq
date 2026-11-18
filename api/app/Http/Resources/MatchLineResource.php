<?php

namespace App\Http\Resources;

use App\Models\MatchLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One invoice line beside its PO line and what was received — the three columns of the
 * matching view — with the deltas already worked out.
 *
 * @mixin MatchLine
 */
class MatchLineResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $message = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $po = $this->poLine;
        $priceDelta = $this->invoice_price_minor !== null && $this->po_price_minor !== null ? $this->invoice_price_minor - $this->po_price_minor : null;
        $cumulative = $this->previously_invoiced_qty + $this->invoiced_qty;

        return [
            'id' => (string) $this->id,
            'line_no' => $this->line_no,
            'sku' => $this->sku,
            'description' => $this->description,
            'method' => $this->method,
            'similarity' => $this->similarity,
            'result' => $this->result,
            'issues' => $this->issues,
            'ok' => $this->isOk(),
            'message' => $this->message,
            'invoice' => ['quantity' => $this->invoiced_qty, 'unit_price_minor' => $this->invoice_price_minor, 'amount_minor' => $this->invoice_amount_minor],
            'po' => $po ? [
                'line_id' => (string) $po->id, 'line_no' => $po->line_no, 'sku' => $po->sku, 'description' => $po->description,
                'quantity' => $this->ordered_qty, 'uom' => $po->uom, 'unit_price_minor' => $this->po_price_minor,
            ] : null,
            'received_qty' => $this->received_qty,
            'previously_invoiced_qty' => $this->previously_invoiced_qty,
            'deltas' => [
                'price_minor' => $priceDelta,
                'price_pct' => $priceDelta !== null && $this->po_price_minor ? round($priceDelta / $this->po_price_minor * 100, 2) : null,
                'qty_vs_ordered' => $this->ordered_qty !== null ? round($cumulative - $this->ordered_qty, 3) : null,
                'qty_vs_received' => $this->received_qty !== null ? round($cumulative - $this->received_qty, 3) : null,
            ],
            'disputed_minor' => $this->disputed_minor,
            'accepted' => $this->accepted_at ? ['at' => $this->accepted_at->toIso8601String(), 'reason' => $this->accept_reason, 'by' => $this->accepted_by ? (string) $this->accepted_by : null] : null,
        ];
    }
}
