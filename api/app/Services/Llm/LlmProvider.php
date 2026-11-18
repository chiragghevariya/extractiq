<?php

namespace App\Services\Llm;

interface LlmProvider
{
    /** Extract a document into its typed schema. The reply must validate against $request->schema. */
    public function extract(ExtractionRequest $request): ExtractionResult;

    /**
     * Classify a document the OCR keyword rules couldn't decide.
     *
     * @return array{type: string, confidence: float, usage?: array}
     */
    public function classify(string $text): array;

    /**
     * Pick which known vendor a document is from when fuzzy name matching is ambiguous.
     * $extracted: {name, tax_id, email, address}; $candidates: [{name, tax_id, email, aliases[]}].
     *
     * @return array{index: ?int, confidence: float, reason: string}
     */
    public function chooseVendor(array $extracted, array $candidates): array;

    public function name(): string;
}
