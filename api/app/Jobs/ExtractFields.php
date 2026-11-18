<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Llm\CostCalculator;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmManager;
use App\Services\Llm\PromptBuilder;
use App\Services\Llm\SchemaRegistry;
use App\Services\Pipeline\ExtractionApplier;
use App\Services\Vendors\HintLearner;
use App\Services\Vendors\VendorMatcher;

/**
 * AI extraction: ocr_done → extracted.
 *  1. If the type is still unknown, ask the LLM to classify (OCR keyword rules come first, in RunOcr).
 *  2. Recognise the vendor from the OCR and load its learned hints (vendor memory).
 *  3. Prompt = OCR rows with line ids + key/values + tables + hints; reply in the versioned
 *     schema via structured output, re-validated server-side.
 *  4. Resolve source word ids, apply values, store extraction + tokens + cost.
 */
class ExtractFields extends PipelineJob
{
    public int $timeout = 300;

    public function step(): string
    {
        return 'extract';
    }

    protected function accepts(): array
    {
        return [DocumentStatus::OcrDone];
    }

    protected function process(Document $document): ?array
    {
        $llm = app(LlmManager::class)->forCompany($document->company_id);
        $schemas = app(SchemaRegistry::class);
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        $classification = null;
        if ($document->type === DocumentType::Unknown) {
            $classification = $llm->classify((string) $document->ocr_text);
            $usage['input_tokens'] += (int) ($classification['usage']['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($classification['usage']['output_tokens'] ?? 0);
            if (in_array($classification['type'], SchemaRegistry::TYPES, true) && $classification['confidence'] >= config('extractiq.extraction.llm_classify_min_confidence')) {
                $document->forceFill(['type' => DocumentType::from($classification['type']), 'type_source' => 'llm'])->saveQuietly();
            }
        }
        // Still unknown: extract as an invoice (the most common case); validation flags the type for review.
        $type = $document->type === DocumentType::Unknown ? 'invoice' : $document->type->value;

        $vendor = app(VendorMatcher::class)->match($document);
        $hints = app(HintLearner::class)->hintsFor($vendor?->id);

        $request = app(PromptBuilder::class)->build($document, $type, $hints);
        $result = $llm->extract($request);

        $errors = $schemas->validate($result->reply, $request->schema);
        if ($errors) {
            // Structured output should make this impossible; treat as a bad reply (retryable once more).
            throw new LlmException('Extraction reply did not match '.$request->schemaId.': '.implode('; ', array_slice($errors, 0, 3)));
        }

        $usage['input_tokens'] += (int) ($result->usage['input_tokens'] ?? 0);
        $usage['output_tokens'] += (int) ($result->usage['output_tokens'] ?? 0);
        $cost = app(CostCalculator::class)->cost($result->model, $usage['input_tokens'], $usage['output_tokens']);

        $document->forceFill([
            'llm_input_tokens' => $usage['input_tokens'],
            'llm_output_tokens' => $usage['output_tokens'],
            'llm_cost_usd' => $cost,
        ]);
        app(ExtractionApplier::class)->apply($document, $type, $result, [
            'hints_used' => $hints,
            'vendor_hint_source' => $vendor?->id,
            'classification' => $classification ? ['type' => $classification['type'], 'confidence' => $classification['confidence']] : null,
            'usage' => $usage,
        ]);
        if ($classification && $document->type_source === null) {
            $document->type_source = 'llm';
        }
        $document->transitionTo(DocumentStatus::Extracted);

        $this->then(fn () => ValidateDocument::dispatch($document->id));

        return ['meta' => array_filter([
            'provider' => $result->provider,
            'model' => $result->model,
            'schema' => $request->schemaId,
            'type' => $type,
            'confidence' => $document->confidence,
            'lines' => count($result->reply['line_items'] ?? []),
            'input_tokens' => $usage['input_tokens'] ?: null,
            'output_tokens' => $usage['output_tokens'] ?: null,
            'cost_usd' => $cost ?: null,
            'hints_used' => count($hints) ?: null,
            'llm_classified_as' => $classification['type'] ?? null,
        ], fn ($v) => $v !== null)];
    }
}
