<?php

namespace App\Services\Validation\Rules;

use App\Enums\DocumentType;
use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

class RequiredFields implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        $label = ['invoice' => 'Invoice number', 'purchase_order' => 'PO number', 'goods_receipt' => 'Receipt number'][$d->type->value] ?? 'Document number';
        $required = match ($d->type) {
            DocumentType::Unknown => ['type' => [null, 'Document type could not be determined.']],
            DocumentType::GoodsReceipt => [
                'document_number' => [$d->document_number, "$label is missing."],
                'document_date' => [$d->document_date, 'Receipt date is missing.'],
                'po_number' => [$d->po_number, 'PO reference is missing.'],
            ],
            default => [
                'document_number' => [$d->document_number, "$label is missing."],
                'document_date' => [$d->document_date, 'Document date is missing.'],
                'vendor_name' => [$d->vendor_name, 'Vendor is missing.'],
                'total' => [$d->total_minor, 'Total amount is missing.'],
                'currency' => [$d->currency, 'Currency is missing.'],
            ],
        };
        if ($d->type !== DocumentType::Unknown) {
            $required['lines'] = [$d->lines->isNotEmpty() ? true : null, 'No line items were found.'];
        }

        $out = [];
        foreach ($required as $field => [$value, $message]) {
            if ($value === null || $value === '') {
                $out[] = RuleResult::fail('required', $message, $field);
            }
        }
        if ($ctx->isMoneyDocument() && $d->total_minor !== null && $d->total_minor <= 0) {
            $out[] = RuleResult::fail('required', 'Total must be greater than zero.', 'total');
        }

        return $out ?: [RuleResult::pass('required', 'All required fields are present.')];
    }
}
