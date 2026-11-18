<?php

namespace App\Services\Validation\Rules;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** Same invoice number from the same vendor already exists (the earliest copy wins). */
class DuplicateInvoice implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        if ($d->type !== DocumentType::Invoice || ! $d->document_number || ! $d->vendor_name) {
            return [];
        }
        $dup = Document::withoutGlobalScopes()
            ->where('company_id', $d->company_id)
            ->where('id', '<', $d->id)
            ->where('type', DocumentType::Invoice->value)
            ->whereRaw('lower(document_number) = ?', [mb_strtolower($d->document_number)])
            ->where(fn ($q) => $d->vendor_id ? $q->where('vendor_id', $d->vendor_id) : $q->whereRaw('lower(vendor_name) = ?', [mb_strtolower($d->vendor_name)]))
            ->first(['id']);

        return $dup
            ? [RuleResult::fail('duplicate', "An invoice with this number from this vendor already exists (document #{$dup->id}).", 'document_number')]
            : [RuleResult::pass('duplicate', 'Invoice number is unique for this vendor.')];
    }
}
