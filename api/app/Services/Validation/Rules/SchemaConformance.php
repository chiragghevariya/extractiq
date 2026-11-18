<?php

namespace App\Services\Validation\Rules;

use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

/** The extracted data must satisfy the versioned schema (date formats, currency codes, …). */
class SchemaConformance implements Rule
{
    public function check(RuleContext $ctx): array
    {
        if (! $ctx->data) {
            return [];
        }
        if (! $ctx->schemaErrors) {
            return [RuleResult::pass('schema', 'Extracted data conforms to the schema.')];
        }

        return array_map(fn ($e) => RuleResult::warn('schema', "Schema: $e"), array_slice($ctx->schemaErrors, 0, 5));
    }
}
