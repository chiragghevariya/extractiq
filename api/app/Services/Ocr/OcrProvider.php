<?php

namespace App\Services\Ocr;

interface OcrProvider
{
    /** Read a document into normalised per-page OCR (words, lines, key/values, tables + raw payload). */
    public function recognize(OcrInput $input): OcrResult;

    public function name(): string;
}
