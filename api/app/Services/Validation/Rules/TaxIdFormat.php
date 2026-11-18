<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** Supplier tax id must look like a real format (US EIN, EU VAT, UK VAT, India GSTIN, AU ABN…). */
class TaxIdFormat implements Rule
{
    public const FORMATS = [
        'US EIN' => '/^\d{2}-?\d{7}$/',
        'EU VAT' => '/^(AT|BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|HR|HU|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK)[0-9A-Z]{8,12}$/',
        'UK VAT' => '/^GB(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
        'India GSTIN' => '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
        'AU ABN' => '/^\d{11}$/',
        'CA BN' => '/^\d{9}(RT\d{4})?$/',
    ];

    public function check(RuleContext $ctx): array
    {
        $raw = $ctx->data['vendor']['tax_id'] ?? ($ctx->document->vendor?->tax_id);
        if (! $raw) {
            return [];
        }
        $id = strtoupper(preg_replace('/[\s.]/', '', (string) $raw));
        foreach (self::FORMATS as $name => $re) {
            if (preg_match($re, $id)) {
                return [RuleResult::pass('tax_id_format', "Tax id $raw is a valid $name format.", 'vendor_tax_id')];
            }
        }

        return [RuleResult::warn('tax_id_format', "Tax id \"$raw\" doesn't match a known format (EIN, EU/UK VAT, GSTIN, ABN).", 'vendor_tax_id')];
    }
}
