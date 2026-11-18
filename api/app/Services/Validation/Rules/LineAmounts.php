<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** Each line: amount = qty × unit price (±0.01). */
class LineAmounts implements Rule
{
    public function check(RuleContext $ctx): array
    {
        if (! $ctx->isMoneyDocument()) {
            return [];
        }
        $out = [];
        $checked = 0;
        foreach ($ctx->document->lines as $line) {
            if ($line->unit_price_minor === null || $line->amount_minor === null) {
                continue;
            }
            $checked++;
            $expected = (int) round($line->quantity * $line->unit_price_minor);
            if (abs($expected - $line->amount_minor) > $ctx->cent()) {
                $out[] = RuleResult::fail('line_math', "Line {$line->line_no}: {$line->quantity} × {$ctx->money($line->unit_price_minor)} = {$ctx->money($expected)}, but the amount is {$ctx->money($line->amount_minor)}.", "lines.{$line->line_no}.amount");
            }
        }

        return $out ?: ($checked ? [RuleResult::pass('line_math', "All $checked line amounts equal qty × unit price.")] : []);
    }
}
