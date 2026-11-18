<?php

namespace App\Services\Ocr;

final class OcrResult
{
    /** @param list<OcrPage> $pages */
    public function __construct(
        public readonly array $pages,
        public readonly string $provider,
        public readonly array $meta = [],
    ) {}

    /** Plain text in reading order — what the extraction step reads. */
    public function text(): string
    {
        return trim(implode("\n\f\n", array_map(fn (OcrPage $p) => $p->plainText ?? $p->text(), $this->pages)));
    }

    public function confidence(): float
    {
        $c = array_filter(array_map(fn (OcrPage $p) => $p->confidence(), $this->pages), fn ($v) => $v !== null);

        return $c ? round(array_sum($c) / count($c), 4) : 0.0;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }
}
