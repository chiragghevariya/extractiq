<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** Reports extracted fields below the confidence threshold (the status rule uses the same check). */
class FieldConfidence implements Rule
{
    public function check(RuleContext $ctx): array
    {
        if (! $ctx->fields) {
            return [];
        }
        $low = self::lowFields($ctx->fields, $ctx->threshold);
        if (! $low) {
            return [RuleResult::pass('confidence', sprintf('All extracted fields are at least %d%% confident.', $ctx->threshold * 100))];
        }

        return [RuleResult::warn('confidence', sprintf('%d field(s) below %d%%: %s.', count($low), $ctx->threshold * 100,
            implode(', ', array_map(fn ($f, $c) => sprintf('%s (%d%%)', self::label($f), $c * 100), array_keys($low), $low))), 'confidence')];
    }

    /** invoice_date → "Invoice date", vendor.tax_id → "Vendor tax id", line_items.0 → "Line 1" */
    public static function label(string $path): string
    {
        if (preg_match('/^line_items\.(\d+)$/', $path, $m)) {
            return 'Line '.($m[1] + 1);
        }

        return ucfirst(str_replace(['.', '_'], ' ', $path));
    }

    /** @return array<string, float> extracted (non-empty) fields below the threshold */
    public static function lowFields(array $fields, float $threshold): array
    {
        $low = [];
        foreach ($fields as $path => $meta) {
            if (($meta['has_value'] ?? true) && (float) $meta['confidence'] < $threshold) {
                $low[$path] = round((float) $meta['confidence'], 2);
            }
        }

        return $low;
    }
}
