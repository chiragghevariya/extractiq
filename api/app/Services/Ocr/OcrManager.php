<?php

namespace App\Services\Ocr;

use App\Services\Settings\ProviderSettings;
use App\Services\Ocr\Drivers\PdfTextOcr;
use App\Services\Ocr\Drivers\TesseractOcr;
use App\Services\Ocr\Drivers\TextractOcr;
use Illuminate\Support\Manager;

/** @mixin OcrProvider */
class OcrManager extends Manager
{
    /** The driver this company chose in Settings (or the environment default). */
    public function forCompany(int $companyId): OcrProvider
    {
        return $this->driver(ProviderSettings::for($companyId)->ocr);
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('extractiq.ocr.default');
    }

    protected function cfg(string $driver): array
    {
        return $this->config->get("extractiq.ocr.drivers.$driver", []);
    }

    public function createPdftextDriver(): OcrProvider
    {
        // Digital PDFs carry a text layer; fall back to Tesseract for scans/images.
        return new PdfTextOcr(fallback: fn () => $this->driver('tesseract'), binary: (string) $this->config->get('extractiq.ocr.drivers.pdftext.binary', 'pdftotext'));
    }

    public function createTesseractDriver(): OcrProvider
    {
        return new TesseractOcr($this->cfg('tesseract'));
    }

    public function createTextractDriver(): OcrProvider
    {
        return new TextractOcr($this->cfg('textract'));
    }
}
