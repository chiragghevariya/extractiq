<?php

namespace App\Services\Validation;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Support\Money;

/** What rules look at: the document (columns + lines) and the extraction behind it. */
final class RuleContext
{
    public function __construct(
        public readonly Document $document,
        /** @var array<string, array{confidence: float, ...}> field path => meta */
        public readonly array $fields = [],
        /** @var array plain extracted values (schema shape) */
        public readonly array $data = [],
        /** @var list<string> schema validation errors for the plain data */
        public readonly array $schemaErrors = [],
        public readonly float $threshold = 0.9,
    ) {}

    public function type(): DocumentType
    {
        return $this->document->type;
    }

    public function isMoneyDocument(): bool
    {
        return in_array($this->document->type, [DocumentType::Invoice, DocumentType::PurchaseOrder], true);
    }

    /** Tolerance of ±0.01 in the document's currency, as minor units. */
    public function cent(): int
    {
        return max(1, (int) (10 ** Money::exponent($this->document->currency) / 100));
    }

    public function money(?int $minor): string
    {
        return Money::format($minor, $this->document->currency ?? 'USD') ?? '—';
    }
}
