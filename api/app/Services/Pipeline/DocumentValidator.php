<?php

namespace App\Services\Pipeline;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Llm\SchemaRegistry;
use App\Services\Validation\Rule;
use App\Services\Validation\RuleContext;
use App\Services\Validation\RuleResult;
use App\Services\Validation\Rules;
use App\Services\Validation\Rules\FieldConfidence;

/**
 * Runs every validation rule (pass | warn | fail + message) and decides the status:
 * validated when no rule fails and every extracted field is ≥ the confidence threshold
 * (0.90); otherwise needs_review. After a human has reviewed, confidence no longer
 * blocks (the person has confirmed the values).
 */
class DocumentValidator
{
    /** @var list<class-string<Rule>> */
    public const RULES = [
        Rules\RequiredFields::class,
        Rules\LineAmounts::class,
        Rules\LinesSumToSubtotal::class,
        Rules\TotalsAddUp::class,
        Rules\DueDateAfterIssue::class,
        Rules\TaxIdFormat::class,
        Rules\DuplicateInvoice::class,
        Rules\VendorKnown::class,
        Rules\SchemaConformance::class,
        FieldConfidence::class,
    ];

    public function __construct(private readonly SchemaRegistry $schemas) {}

    /** @return array{results: list<array>, errors: list<array>, warnings: list<array>, status: DocumentStatus} */
    public function validate(Document $doc, bool $humanReviewed = false): array
    {
        $doc->loadMissing('lines', 'vendor');
        $extraction = $doc->extraction ?? [];
        $fields = $extraction['fields'] ?? [];
        $data = $extraction['data'] ?? [];
        $schemaErrors = [];
        if ($data && in_array($doc->type->value, SchemaRegistry::TYPES, true) && ($extraction['schema'] ?? null) === $this->schemas->id($doc->type->value)) {
            $schemaErrors = $this->schemas->validate($data, $this->schemas->plain($doc->type->value));
        }
        $threshold = (float) config('extractiq.review_confidence_threshold');
        $ctx = new RuleContext($doc, $humanReviewed ? [] : $fields, $data, $schemaErrors, $threshold);

        $results = [];
        foreach (self::RULES as $rule) {
            foreach (app($rule)->check($ctx) as $r) {
                $results[] = $r;
            }
        }

        $fails = array_values(array_filter($results, fn (RuleResult $r) => $r->status === RuleResult::FAIL));
        $warns = array_values(array_filter($results, fn (RuleResult $r) => $r->status === RuleResult::WARN));
        $confident = $humanReviewed || ! FieldConfidence::lowFields($fields, $threshold);

        $issue = fn (RuleResult $r) => ['field' => $r->field ?? $r->rule, 'code' => $r->rule, 'message' => $r->message];

        return [
            'results' => array_map(fn (RuleResult $r) => $r->toArray(), $results),
            'errors' => array_map($issue, $fails),
            'warnings' => array_map($issue, $warns),
            'status' => ! $fails && $confident ? DocumentStatus::Validated : DocumentStatus::NeedsReview,
        ];
    }
}
