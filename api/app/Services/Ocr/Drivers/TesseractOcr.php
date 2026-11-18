<?php

namespace App\Services\Ocr\Drivers;

use App\Services\Ocr\Normalizer;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Local OCR with the tesseract CLI — the fallback when there are no AWS keys and for
 * scans/images. Runs on the 300 DPI page images from ProcessPages (or rasterises the
 * PDF itself if they're missing) and returns the same normalised shape as Textract.
 */
class TesseractOcr implements OcrProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function recognize(OcrInput $input): OcrResult
    {
        $dir = storage_path('app/tmp/ocr-'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $images = $this->images($input, $dir);
            if (! $images) {
                throw new OcrException('Could not rasterise document.');
            }

            $pages = [];
            foreach ($images as $n => $img) {
                $tsv = $this->run([$this->config['binary'] ?? 'tesseract', $img['path'], 'stdout', '-l', $this->config['lang'] ?? 'eng', '--psm', (string) ($this->config['psm'] ?? 3), 'tsv']);
                $pages[] = $this->pageFromTsv($n + 1, $tsv, $img['width'], $img['height']);
            }

            return new OcrResult($pages, $this->name());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @return list<array{path: string, width: int, height: int}> */
    private function images(OcrInput $input, string $dir): array
    {
        $out = [];
        if ($input->pageImages) {
            foreach ($input->pageImages as $p) {
                $path = "$dir/p{$p['page_number']}.png";
                File::put($path, ($p['bytes'])());
                [$w, $h] = $p['width'] && $p['height'] ? [$p['width'], $p['height']] : (getimagesize($path) ?: [0, 0]);
                $out[] = ['path' => $path, 'width' => (int) $w, 'height' => (int) $h];
            }

            return $out;
        }

        if ($input->mimeType === 'application/pdf') {
            File::put("$dir/in.pdf", ($input->bytes)());
            $this->run([$this->config['pdftoppm'] ?? 'pdftoppm', '-r', (string) ($this->config['dpi'] ?? 300), '-png', "$dir/in.pdf", "$dir/page"]);
            $files = glob("$dir/page-*.png") ?: [];
            usort($files, fn ($a, $b) => (int) preg_replace('/\D/', '', basename($a)) <=> (int) preg_replace('/\D/', '', basename($b)));
        } else {
            File::put("$dir/in.img", ($input->bytes)());
            $files = ["$dir/in.img"];
        }
        foreach ($files as $f) {
            [$w, $h] = getimagesize($f) ?: [0, 0];
            $out[] = ['path' => $f, 'width' => (int) $w, 'height' => (int) $h];
        }

        return $out;
    }

    /** Tesseract TSV (level 5 = word) → normalised page; lines grouped by block/paragraph/line ids. */
    public function pageFromTsv(int $number, string $tsv, int $width, int $height): OcrPage
    {
        $words = [];
        $groups = [];
        $raw = [];
        foreach (array_slice(explode("\n", trim($tsv)), 1) as $row) {
            $c = explode("\t", $row);
            if (count($c) < 12 || $c[0] !== '5' || trim($c[11]) === '' || (float) $c[10] < 0) {
                continue;
            }
            [$left, $top, $w, $h] = [(int) $c[6], (int) $c[7], (int) $c[8], (int) $c[9]];
            $word = [
                'text' => $c[11],
                'bbox' => Normalizer::bbox($left, $top, $left + $w, $top + $h, max(1, $width ?: $left + $w), max(1, $height ?: $top + $h)),
                'confidence' => round(((float) $c[10]) / 100, 4),
            ];
            $words[] = $word;
            $groups["{$c[2]}-{$c[3]}-{$c[4]}"][] = $word;
            $raw[] = ['block' => (int) $c[2], 'par' => (int) $c[3], 'line' => (int) $c[4], 'left' => $left, 'top' => $top, 'width' => $w, 'height' => $h, 'conf' => (float) $c[10], 'text' => $c[11]];
        }
        $lines = array_values(array_map(fn ($ws) => Normalizer::line($ws), $groups));
        usort($lines, fn ($a, $b) => [round($a['bbox'][1], 3), $a['bbox'][0]] <=> [round($b['bbox'][1], 3), $b['bbox'][0]]);

        return new OcrPage(
            number: $number,
            words: $words,
            lines: $lines,
            keyValues: Normalizer::keyValuesFromLines($lines, $words),
            tables: [],
            raw: ['format' => 'tesseract-tsv', 'image_width' => $width, 'image_height' => $height, 'words' => $raw],
            plainText: Normalizer::rowsText($lines),
        );
    }

    private function run(array $cmd): string
    {
        $result = Process::timeout(180)->run($cmd);
        if (! $result->successful()) {
            throw new OcrException(trim($result->errorOutput()) ?: 'OCR command failed: '.$cmd[0]);
        }

        return $result->output();
    }
}
