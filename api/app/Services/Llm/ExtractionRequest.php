<?php

namespace App\Services\Llm;

/** Everything a provider needs to extract one document into its typed schema. */
final class ExtractionRequest
{
    /**
     * @param  array  $schema  wrapped extraction schema (SchemaRegistry::extraction)
     * @param  list<array{ids: list<string>, text: string}>  $rows  OCR visual rows with line ids
     * @param  list<array{field: string, hint: string, label: ?string}>  $hints  vendor-specific hints
     */
    public function __construct(
        public readonly string $type,
        public readonly string $schemaId,
        public readonly array $schema,
        public readonly string $system,
        public readonly string $prompt,
        public readonly array $rows = [],
        public readonly array $keyValues = [],
        public readonly array $hints = [],
        public readonly ?string $fileBytes = null,
        public readonly ?string $mimeType = null,
        public readonly string $ocrText = '',
    ) {}
}
