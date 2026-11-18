<?php

namespace App\Http\Resources;

use App\Models\DocumentMatch;
use App\Models\GoodsReceipt;
use Illuminate\Http\Request;

/** @mixin DocumentMatch */
class MatchResource extends JsonApiResource
{
    public function type(): string
    {
        return 'matches';
    }

    public function fields(Request $request): array
    {
        $po = $this->purchaseOrder;
        $messages = collect($this->discrepancies)->filter(fn ($d) => isset($d['line_no']))->pluck('message', 'line_no');

        return [
            'kind' => $this->kind,
            'result' => $this->result,
            'is_current' => $this->is_current,
            'po_method' => $this->po_method,
            'po_confidence' => $this->po_confidence,
            'header_issues' => $this->header_issues,
            'discrepancies' => $this->discrepancies,
            'totals' => $this->totals,
            'tolerances' => $this->tolerances,
            'line_counts' => $this->relationLoaded('lines') ? $this->lines->countBy('result')->all() : null,
            'accepted' => $this->accepted_at ? ['by' => $this->acceptedBy?->name, 'at' => $this->accepted_at->toIso8601String(), 'reason' => $this->accept_reason] : null,
            'credit_note' => $this->credit_note_requested_at ? [
                'by' => $this->creditNoteRequestedBy?->name, 'at' => $this->credit_note_requested_at->toIso8601String(),
                'amount_minor' => $this->credit_note_amount_minor, 'reason' => $this->credit_note_reason,
            ] : null,
            'purchase_order' => $po ? [
                'id' => (string) $po->id, 'number' => $po->po_number, 'status' => $po->status, 'source' => $po->source,
                'vendor_id' => $po->vendor_id ? (string) $po->vendor_id : null, 'vendor_name' => $po->vendor?->name,
                'order_date' => $po->order_date?->toDateString(), 'currency' => $po->currency, 'total_minor' => $po->total_minor,
                'source_document_id' => $po->source_document_id ? (string) $po->source_document_id : null,
            ] : null,
            'goods_receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn (GoodsReceipt $g) => [
                'id' => (string) $g->id, 'number' => $g->grn_number, 'received_date' => $g->received_date?->toDateString(), 'source' => $g->source,
                'source_document_id' => $g->source_document_id ? (string) $g->source_document_id : null,
            ])->values(), fn () => array_map('strval', $this->goods_receipt_ids ?? [])),
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => (string) $this->invoice_id, 'number' => $this->invoice->document_number, 'vendor_name' => $this->invoice->vendor_name,
                'total_minor' => $this->invoice->total_minor, 'currency' => $this->invoice->currency, 'status' => $this->invoice->status->value,
                'on_hold' => $this->invoice->on_hold_at !== null, 'hold_reason' => $this->invoice->hold_reason,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => (new MatchLineResource($l, $messages[$l->line_no] ?? null))->resolve($request))->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
