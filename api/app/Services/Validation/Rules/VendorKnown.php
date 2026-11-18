<?php

namespace App\Services\Validation\Rules;

use App\Enums\DocumentType;
use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

class VendorKnown implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        $out = [];
        if ($d->vendor_name || $d->vendor_id) {
            $method = $d->vendor_match['method'] ?? null;
            $pct = (int) round(($d->vendor_match['confidence'] ?? 0) * 100);
            $name = $d->vendor?->name;
            $out[] = match (true) {
                ! $d->vendor_id => RuleResult::warn('vendor_known', "Vendor \"{$d->vendor_name}\" is not in the vendor list.", 'vendor_name'),
                $method === 'fuzzy' => RuleResult::warn('vendor_known', "Vendor matched to {$name} by a similar name ({$pct}%). Check it's the same company.", 'vendor_name'),
                $method === 'llm' => RuleResult::warn('vendor_known', "Vendor matched to {$name} by AI tie-break ({$pct}%). Check it's the same company.", 'vendor_name'),
                default => RuleResult::pass('vendor_known', "Vendor matched to {$name}".match ($method) {
                    'tax_id' => ' by tax ID.', 'alias' => ' by a known alias.', 'manual' => ' by a reviewer.', default => '.',
                }, 'vendor_name'),
            };
        }
        if ($d->type === DocumentType::Invoice && ! $d->po_number) {
            $out[] = RuleResult::warn('missing_po', 'No PO number — the invoice cannot be matched automatically.', 'po_number');
        }

        return $out;
    }
}
