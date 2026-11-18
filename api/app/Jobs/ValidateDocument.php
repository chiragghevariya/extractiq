<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Pipeline\AutomationSettings;
use App\Services\Pipeline\DocumentValidator;
use App\Services\Pipeline\DocumentWorkflow;

/** Rules engine → validated (no fails, every field ≥ 0.90) or needs_review. Stores every rule result. */
class ValidateDocument extends PipelineJob
{
    public function step(): string
    {
        return 'validate';
    }

    protected function accepts(): array
    {
        return [DocumentStatus::Extracted, DocumentStatus::NeedsReview, DocumentStatus::Validated];
    }

    protected function process(Document $document): ?array
    {
        // Human confirmations are carried in the per-field confidences (edited/accepted fields = 1.0).
        $result = app(DocumentValidator::class)->validate($document);
        $document->validation = ['errors' => $result['errors'], 'warnings' => $result['warnings'], 'checked_at' => now()->toIso8601String()];
        $document->rule_results = $result['results'];
        $document->transitionTo($result['status']);
        $document->save();

        $touchless = $result['status'] === DocumentStatus::Validated && AutomationSettings::for($document->company_id)->appliesTo($document);
        if ($result['status'] === DocumentStatus::Validated && (config('extractiq.pipeline.auto_approve_validated') || $touchless)) {
            if ($touchless) {
                $document->forceFill(['touchless' => true])->saveQuietly();
            }
            $this->then(fn () => app(DocumentWorkflow::class)->approve($document->fresh(), null));
        }

        $count = fn (string $s) => count(array_filter($result['results'], fn ($r) => $r['status'] === $s));

        return ['meta' => ['touchless' => $touchless ?: null, 'result' => $result['status']->value, 'pass' => $count('pass'), 'warn' => $count('warn'), 'fail' => $count('fail'), 'errors' => count($result['errors']), 'warnings' => count($result['warnings'])]];
    }
}
