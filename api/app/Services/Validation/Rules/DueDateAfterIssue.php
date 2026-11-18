<?php

namespace App\Services\Validation\Rules;

use App\Enums\DocumentType;
use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;

class DueDateAfterIssue implements Rule
{
    public function check(RuleContext $ctx): array
    {
        $d = $ctx->document;
        $out = [];
        if ($d->document_date && $d->document_date->isAfter(now()->addDays(7))) {
            $out[] = RuleResult::warn('future_date', 'Document date is more than a week in the future.', 'document_date');
        }
        if ($d->type !== DocumentType::Invoice || ! $d->due_date || ! $d->document_date) {
            return $out;
        }
        $out[] = $d->due_date->lt($d->document_date)
            ? RuleResult::fail('due_after_issue', "Due date {$d->due_date->toDateString()} is before the invoice date {$d->document_date->toDateString()}.", 'due_date')
            : RuleResult::pass('due_after_issue', 'Due date is on or after the invoice date.');

        return $out;
    }
}
