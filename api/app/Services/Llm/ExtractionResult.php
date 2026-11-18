<?php

namespace App\Services\Llm;

/** A provider's raw reply in the wrapped extraction schema, plus usage for cost tracking. */
final class ExtractionResult
{
    /** @param array{input_tokens?: ?int, output_tokens?: ?int} $usage */
    public function __construct(
        public readonly array $reply,
        public readonly string $provider,
        public readonly ?string $model = null,
        public readonly array $usage = [],
    ) {}
}
