<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

class LinesSumToSubtotal implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        if (! $ctx->isMoneyDocument() || $d->lines->whereNotNull('amount_minor')->isEmpty()) {
            return [];
        }
        $sum = (int) $d->lines->sum('amount_minor');
        $subtotal = $d->subtotal_minor ?? ($d->total_minor !== null ? $d->total_minor - (int) $d->tax_minor - (int) $d->shipping_minor : null);
        if ($subtotal === null) {
            return [];
        }

        return abs($sum - $subtotal) > $ctx->cent()
            ? [RuleResult::fail('lines_sum', "Line items add up to {$ctx->money($sum)}, but the subtotal is {$ctx->money($subtotal)}.", 'subtotal')]
            : [RuleResult::pass('lines_sum', 'Line items add up to the subtotal.')];
    }
}
