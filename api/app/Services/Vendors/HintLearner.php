<?php

namespace App\Services\Vendors;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\User;
use App\Models\VendorHint;
use App\Services\Ocr\Normalizer;
use Illuminate\Support\Facades\DB;

/**
 * Vendor memory. When a reviewer corrects a field, find where that value actually appears
 * in the OCR — as an OCR key/value pair or a "Label: value" line — and remember the label
 * for this vendor ("PO number appears as \"Order Ref\""). Hints are injected into the next
 * extraction for the same vendor (PromptBuilder; the offline extractor uses the label too).
 */
class HintLearner
{
    public const FIELD_LABELS = [
        'po_number' => 'PO number', 'invoice_number' => 'Invoice number', 'invoice_date' => 'Invoice date', 'due_date' => 'Due date',
        'vendor.tax_id' => 'Tax ID', 'vendor.name' => 'Vendor name', 'currency' => 'Currency', 'subtotal' => 'Subtotal',
        'tax_total' => 'Tax total', 'shipping' => 'Shipping', 'total' => 'Total', 'payment_terms' => 'Payment terms',
        'order_date' => 'Order date', 'grn_number' => 'Receipt number', 'receipt_date' => 'Receipt date', 'delivery_date' => 'Delivery date',
    ];

    public function learn(Document $doc, string $field, mixed $value, ?User $user): ?VendorHint
    {
        if (! $doc->vendor_id || $value === null || $value === '' || ! isset(self::FIELD_LABELS[$field])) {
            return null;
        }
        $label = $this->findLabel($doc, (string) $value);
        if (! $label) {
            return null;
        }

        return VendorHint::withoutGlobalScopes()->updateOrCreate(
            ['vendor_id' => $doc->vendor_id, 'field' => $field, 'label' => $label],
            [
                'company_id' => $doc->company_id,
                'hint' => sprintf('%s appears as "%s" on this vendor\'s documents.', self::FIELD_LABELS[$field], $label),
                'example_value' => mb_substr((string) $value, 0, 255),
                'source_document_id' => $doc->id,
                'created_by' => $user?->id,
            ],
        );
    }

    /** @return list<array{field: string, hint: string, label: ?string}> (also bumps usage counters) */
    public function hintsFor(?int $vendorId): array
    {
        if (! $vendorId) {
            return [];
        }
        $hints = VendorHint::withoutGlobalScopes()->where('vendor_id', $vendorId)->orderBy('id')->get();
        if ($hints->isNotEmpty()) {
            VendorHint::withoutGlobalScopes()->whereIn('id', $hints->pluck('id'))->update(['times_used' => DB::raw('times_used + 1'), 'last_used_at' => now()]);
        }

        return $hints->map(fn (VendorHint $h) => ['field' => $h->field, 'hint' => $h->hint, 'label' => $h->label])->values()->all();
    }

    private function findLabel(Document $doc, string $value): ?string
    {
        $needle = $this->norm($value);
        if ($needle === '') {
            return null;
        }
        $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('page_number')->get();
        foreach ($pages as $p) {
            foreach ($p->ocr['key_values'] ?? [] as $kv) {
                if ($this->norm((string) $kv['value']) === $needle && $this->goodLabel((string) $kv['key'])) {
                    return trim((string) $kv['key']);
                }
            }
        }
        foreach ($pages as $p) {
            foreach ($p->ocr['lines'] ?? [] as $line) {
                $text = (string) $line['text'];
                $pos = mb_stripos($text, $value);
                if ($pos !== false && $pos > 0) {
                    $label = trim(rtrim(trim(mb_substr($text, 0, $pos)), ':#-'));
                    if ($this->goodLabel($label)) {
                        return $label;
                    }
                }
            }
        }
        // Two-column layouts: the label and the value are separate OCR lines on one visual row
        // ("Order Ref" … "PO-1005"). Take the segment just before the value.
        foreach ($pages as $p) {
            foreach (Normalizer::rows((int) $p->page_number, $p->ocr['lines'] ?? []) as $row) {
                $segments = preg_split('/\s{2,}/', $row['text']) ?: [];
                foreach ($segments as $i => $segment) {
                    if ($i > 0 && $this->norm($segment) === $needle) {
                        $label = trim(rtrim(trim($segments[$i - 1]), ':#-'));
                        if ($this->goodLabel($label)) {
                            return $label;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function goodLabel(string $label): bool
    {
        $label = trim($label);

        return mb_strlen($label) >= 2 && mb_strlen($label) <= 40 && preg_match('/[A-Za-z]/', $label) === 1 && preg_match('/\d{3,}/', $label) === 0;
    }

    private function norm(string $v): string
    {
        return (string) preg_replace('/[^a-z0-9.]/', '', mb_strtolower($v));
    }
}
