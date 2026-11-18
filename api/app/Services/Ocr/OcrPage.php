<?php

namespace App\Services\Ocr;

/**
 * Normalised OCR for one page — identical shape for every provider.
 * All bboxes are [x, y, w, h] as 0..1 ratios of the page, origin top-left.
 */
final class OcrPage
{
    /**
     * @param  list<array{text: string, bbox: array{float, float, float, float}, confidence: float}>  $words
     * @param  list<array{text: string, bbox: array{float, float, float, float}, confidence: float}>  $lines
     * @param  list<array{key: string, value: string, confidence: float, key_bbox: ?array{float, float, float, float}, value_bbox: ?array{float, float, float, float}}>  $keyValues
     * @param  list<array{bbox: ?array{float, float, float, float}, confidence: float, rows: list<list<string>>}>  $tables
     * @param  array<string, mixed>  $raw  provider payload for this page
     */
    public function __construct(
        public readonly int $number,
        public readonly array $words,
        public readonly array $lines,
        public readonly array $keyValues = [],
        public readonly array $tables = [],
        public readonly array $raw = [],
        public readonly ?float $widthPt = null,
        public readonly ?float $heightPt = null,
        /** Layout-preserving page text when the provider has one (keeps table rows on one line). */
        public readonly ?string $plainText = null,
    ) {}

    public function text(): string
    {
        return implode("\n", array_map(fn ($l) => $l['text'], $this->lines));
    }

    public function confidence(): ?float
    {
        if (! $this->words) {
            return null;
        }

        return round(array_sum(array_column($this->words, 'confidence')) / count($this->words), 4);
    }

    /** @return array{words: list<mixed>, lines: list<mixed>, key_values: list<mixed>, tables: list<mixed>} */
    public function normalised(): array
    {
        return ['words' => $this->words, 'lines' => $this->lines, 'key_values' => $this->keyValues, 'tables' => $this->tables];
    }
}
