<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** subtotal + tax + shipping = total (±0.01). */
class TotalsAddUp implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        if (! $ctx->isMoneyDocument() || $d->subtotal_minor === null || $d->total_minor === null) {
            return [];
        }
        $expected = $d->subtotal_minor + (int) $d->tax_minor + (int) $d->shipping_minor;

        return abs($expected - $d->total_minor) > $ctx->cent()
            ? [RuleResult::fail('total_math', "Subtotal + tax + shipping = {$ctx->money($expected)}, but the total is {$ctx->money($d->total_minor)}.", 'total')]
            : [RuleResult::pass('total_math', 'Subtotal + tax + shipping equals the total.')];
    }
}
