<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Matching\MatchingEngine;

class MatchDocument extends PipelineJob
{
    public function step(): string
    {
        return 'match';
    }

    protected function accepts(): array
    {
        return [DocumentStatus::Approved, DocumentStatus::MatchException];
    }

    protected function process(Document $document): ?array
    {
        if (! $document->type->flowsToErp()) {
            return ['meta' => ['skipped' => 'not_an_invoice']];
        }

        $match = app(MatchingEngine::class)->match($document);
        $document->transitionTo($match->result === 'matched' ? DocumentStatus::Matched : DocumentStatus::MatchException);

        if ($match->result === 'matched' && ! $document->onHold()) {
            $this->then(fn () => PushToErp::dispatch($document->id));
        }

        return ['meta' => [
            'kind' => $match->kind, 'result' => $match->result, 'discrepancies' => count($match->discrepancies), 'match_id' => $match->id,
            'po' => $match->purchaseOrder?->po_number, 'po_method' => $match->po_method,
            'lines' => $match->lines->countBy('result')->all(),
        ]];
    }
}
