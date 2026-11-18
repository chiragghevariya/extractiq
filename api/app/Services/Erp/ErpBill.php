<?php

namespace App\Services\Erp;

use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;

/**
 * A connector-neutral vendor bill built from an approved, matched invoice, with the company's
 * mapping already applied: every line carries its GL account, tax code and cost centre, and
 * `fields` holds the header values under the ERP's own field names.
 */
final class ErpBill
{
    /**
     * @param  list<array{line_no: int, description: string, sku: ?string, quantity: float, uom: ?string, unit_price: float, amount: float, tax_rate: ?float, account: ?string, tax_code: ?string, cost_centre: ?string, po_line_ref: ?string}>  $lines
     * @param  array<string, string>  $fields  ERP field => value
     * @param  ?array{filename: string, mime: string, disk: string, path: string}  $attachment
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $vendorName,
        public readonly ?string $vendorRef,
        public readonly ?string $vendorTaxId,
        public readonly string $number,
        public readonly ?string $poNumber,
        public readonly string $date,
        public readonly ?string $dueDate,
        public readonly string $currency,
        public readonly array $lines,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly array $fields = [],
        public readonly ?string $poRef = null,
        public readonly ?array $attachment = null,
        public readonly int $shippingMinor = 0,
        public readonly ?string $documentUrl = null,
    ) {}

    public static function fromDocument(Document $doc, ErpMapping $mapping): self
    {
        $doc->loadMissing('lines', 'vendor', 'latestMatch.lines.poLine');
        $currency = $doc->currency ?? 'USD';
        $div = 10 ** Money::exponent($currency);
        $docRate = $doc->subtotal_minor ? round(($doc->tax_minor ?? 0) / $doc->subtotal_minor * 100, 2) : null;
        $poLines = $doc->latestMatch?->lines->keyBy('line_no');
        $po = $doc->latestMatch?->purchase_order_id ? PurchaseOrder::withoutGlobalScopes()->find($doc->latestMatch->purchase_order_id) : null;

        $values = [
            'document_number' => (string) $doc->document_number,
            'po_number' => $po->po_number ?? $doc->po_number,
            'document_date' => $doc->document_date?->toDateString(),
            'due_date' => $doc->due_date?->toDateString(),
            'payment_terms' => $doc->payment_terms,
            'document_uuid' => 'ExtractIQ '.$doc->uuid,
        ];
        $fields = [];
        foreach ($values as $ours => $value) {
            if (($target = $mapping->target($ours)) && $value !== null && $value !== '') {
                // Several of ours can share a free-text field (e.g. Odoo narration): join them.
                $fields[$target] = isset($fields[$target]) ? $fields[$target]."\n".$value : (string) $value;
            }
        }

        return new self(
            idempotencyKey: (string) $doc->uuid,
            vendorName: $doc->vendor->name ?? (string) $doc->vendor_name,
            vendorRef: $doc->vendor?->erp_ref,
            vendorTaxId: $doc->vendor?->tax_id,
            number: (string) $doc->document_number,
            poNumber: $values['po_number'],
            date: $doc->document_date?->toDateString() ?? now()->toDateString(),
            dueDate: $doc->due_date?->toDateString(),
            currency: $currency,
            lines: $doc->lines->sortBy('line_no')->values()->map(function ($l) use ($div, $mapping, $doc, $docRate, $poLines) {
                $rate = $l->tax_rate ?? $docRate;

                return [
                    'line_no' => $l->line_no,
                    'description' => $l->description,
                    'sku' => $l->sku,
                    'quantity' => (float) $l->quantity,
                    'uom' => $l->uom,
                    'unit_price' => ($l->unit_price_minor ?? 0) / $div,
                    'amount' => ($l->amount_minor ?? 0) / $div,
                    'tax_rate' => $rate,
                    'account' => $mapping->accountFor($doc->vendor_id),
                    'tax_code' => $mapping->taxCodeFor($rate),
                    'cost_centre' => $mapping->costCentreFor($doc->vendor_id),
                    'po_line_ref' => $poLines?->get($l->line_no)?->poLine?->erp_ref,
                ];
            })->all(),
            subtotalMinor: (int) $doc->subtotal_minor,
            taxMinor: (int) $doc->tax_minor,
            totalMinor: (int) $doc->total_minor,
            fields: $fields,
            poRef: $po?->erp_ref,
            attachment: ['filename' => $doc->original_filename, 'mime' => $doc->mime_type, 'disk' => $doc->storage_disk, 'path' => $doc->storage_path],
            shippingMinor: (int) $doc->shipping_minor,
            documentUrl: rtrim((string) config('extractiq.web_url'), '/')."/documents/{$doc->id}",
        );
    }

    public function attachmentContents(): ?string
    {
        if (! $this->attachment) {
            return null;
        }
        $disk = Storage::disk($this->attachment['disk']);

        return $disk->exists($this->attachment['path']) ? $disk->get($this->attachment['path']) : null;
    }

    public function money(int $minor): float
    {
        return $minor / 10 ** Money::exponent($this->currency);
    }

    /** For logs and webhooks: everything except the file bytes. */
    public function toArray(): array
    {
        $out = get_object_vars($this);
        if ($this->attachment) {
            $out['attachment'] = ['filename' => $this->attachment['filename'], 'mime' => $this->attachment['mime']];
        }

        return $out;
    }
}
