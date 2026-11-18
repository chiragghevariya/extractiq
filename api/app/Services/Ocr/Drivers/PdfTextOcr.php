<?php

namespace App\Services\Ocr\Drivers;

use App\Services\Ocr\Normalizer;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrResult;
use Closure;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Digital PDFs: reads the embedded text layer with poppler (`pdftotext -bbox-layout`
 * for word/line boxes, `-layout` for reading-order text). Exact boxes, confidence 1.0,
 * milliseconds per page. Scans and images (no usable text layer) go to the fallback
 * provider (Tesseract).
 */
class PdfTextOcr implements OcrProvider
{
    public function __construct(private readonly ?Closure $fallback = null, private readonly string $binary = 'pdftotext') {}

    public function name(): string
    {
        return 'pdftext';
    }

    public function recognize(OcrInput $input): OcrResult
    {
        if ($input->mimeType === 'application/pdf') {
            $dir = storage_path('app/tmp/pdftext-'.Str::uuid());
            File::ensureDirectoryExists($dir);
            try {
                File::put("$dir/in.pdf", ($input->bytes)());
                $bbox = Process::timeout(120)->run([$this->binary, '-bbox-layout', "$dir/in.pdf", "$dir/bbox.html"]);
                $layout = Process::timeout(120)->run([$this->binary, '-layout', "$dir/in.pdf", "$dir/layout.txt"]);
                if ($bbox->successful() && is_file("$dir/bbox.html")) {
                    $pages = $this->parse(File::get("$dir/bbox.html"), $layout->successful() && is_file("$dir/layout.txt") ? File::get("$dir/layout.txt") : null);
                    $chars = array_sum(array_map(fn (OcrPage $p) => mb_strlen(preg_replace('/\s+/', '', $p->text())), $pages));
                    if ($pages && $chars >= 40) {
                        return new OcrResult($pages, $this->name());
                    }
                }
            } finally {
                File::deleteDirectory($dir);
            }
        }

        if ($this->fallback) {
            return ($this->fallback)()->recognize($input);
        }

        throw new OcrException('No text layer found and no OCR fallback configured.');
    }

    /** @return list<OcrPage> */
    public function parse(string $xhtml, ?string $layoutText = null): array
    {
        $dom = new DOMDocument;
        @$dom->loadXML($xhtml) ?: @$dom->loadHTML($xhtml);
        $layoutPages = $layoutText !== null ? explode("\f", $layoutText) : [];

        $pages = [];
        foreach ($dom->getElementsByTagName('page') as $i => $pageEl) {
            /** @var DOMElement $pageEl */
            $pw = (float) $pageEl->getAttribute('width');
            $ph = (float) $pageEl->getAttribute('height');
            $words = [];
            $lines = [];
            $rawLines = [];
            foreach ($pageEl->getElementsByTagName('line') as $lineEl) {
                /** @var DOMElement $lineEl */
                $lineWords = [];
                foreach ($lineEl->getElementsByTagName('word') as $w) {
                    /** @var DOMElement $w */
                    $word = [
                        'text' => $w->textContent,
                        'bbox' => Normalizer::bbox((float) $w->getAttribute('xMin'), (float) $w->getAttribute('yMin'), (float) $w->getAttribute('xMax'), (float) $w->getAttribute('yMax'), $pw, $ph),
                        'confidence' => 1.0,
                    ];
                    $lineWords[] = $word;
                    $words[] = $word;
                }
                if ($lineWords) {
                    $lines[] = Normalizer::line($lineWords);
                    $rawLines[] = ['text' => end($lines)['text'], 'xMin' => (float) $lineEl->getAttribute('xMin'), 'yMin' => (float) $lineEl->getAttribute('yMin'), 'xMax' => (float) $lineEl->getAttribute('xMax'), 'yMax' => (float) $lineEl->getAttribute('yMax')];
                }
            }
            // Reading order: top-to-bottom, then left-to-right.
            usort($lines, fn ($a, $b) => [round($a['bbox'][1], 3), $a['bbox'][0]] <=> [round($b['bbox'][1], 3), $b['bbox'][0]]);

            $plain = isset($layoutPages[$i]) ? rtrim(preg_replace('/[ \t]+$/m', '', $layoutPages[$i])) : null;
            $pages[] = new OcrPage(
                number: $i + 1,
                words: $words,
                lines: $lines,
                keyValues: Normalizer::keyValuesFromLines($lines, $words),
                tables: [],
                raw: ['format' => 'pdftotext-bbox', 'width_pt' => $pw, 'height_pt' => $ph, 'lines' => $rawLines],
                widthPt: $pw,
                heightPt: $ph,
                plainText: $plain,
            );
        }

        return $pages;
    }
}
