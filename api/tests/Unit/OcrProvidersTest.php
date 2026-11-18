<?php

use App\Services\Ocr\Drivers\PdfTextOcr;
use App\Services\Ocr\Drivers\TesseractOcr;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrResult;

function pdfInput(string $sample, array $extra = []): OcrInput
{
    $bytes = file_get_contents(samplePath($sample));

    return new OcrInput(...(['bytes' => fn () => $bytes, 'mimeType' => 'application/pdf'] + $extra));
}

/** Every provider must return this exact shape. */
function assertNormalisedShape(OcrResult $r): void
{
    expect($r->pages)->not->toBeEmpty();
    foreach ($r->pages as $page) {
        $n = $page->normalised();
        expect(array_keys($n))->toBe(['words', 'lines', 'key_values', 'tables'])->and($n['words'])->not->toBeEmpty();
        foreach ([...$n['words'], ...$n['lines']] as $w) {
            expect($w)->toHaveKeys(['text', 'bbox', 'confidence'])->and($w['bbox'])->toHaveCount(4)
                ->and($w['confidence'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
            foreach ($w['bbox'] as $v) {
                expect($v)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
            }
        }
        foreach ($n['key_values'] as $kv) {
            expect($kv)->toHaveKeys(['key', 'value', 'confidence', 'key_bbox', 'value_bbox']);
        }
    }
}

it('reads a digital PDF with exact word boxes via poppler', function () {
    $r = (new PdfTextOcr)->recognize(pdfInput('03-inv-k-2041-kestrel-3way-match.pdf'));
    assertNormalisedShape($r);
    $page = $r->pages[0];

    $word = collect($page->words)->firstWhere('text', 'INV-K-2041');
    expect($r->provider)->toBe('pdftext')
        ->and($word['confidence'])->toBe(1.0)
        ->and($word['bbox'][0])->toBeGreaterThan(0.05)->toBeLessThan(0.3)   // left column
        ->and($word['bbox'][1])->toBeGreaterThan(0.1)->toBeLessThan(0.2)    // near the top
        ->and(collect($page->keyValues)->firstWhere('key', 'Invoice No'))->toMatchArray(['value' => 'INV-K-2041'])
        ->and(collect($page->keyValues)->firstWhere('key', 'Invoice No')['value_bbox'])->toBe($word['bbox'])
        ->and($r->text())->toMatch('/KIP-2040\s+Bearing 6204-2RS\s+40\s+6\.75\s+270\.00/')   // table row kept on one line
        ->and($page->raw['format'])->toBe('pdftotext-bbox');
});

it('falls back to another provider when there is no text layer', function () {
    $fallback = new class implements OcrProvider
    {
        public function recognize(OcrInput $input): OcrResult
        {
            return new OcrResult([], 'fake');
        }

        public function name(): string
        {
            return 'fake';
        }
    };

    $r = (new PdfTextOcr(fn () => $fallback))->recognize(new OcrInput(fn () => 'png-bytes', 'image/png'));

    expect($r->provider)->toBe('fake');
});

it('fails without a fallback when there is no text layer', function () {
    (new PdfTextOcr)->recognize(new OcrInput(fn () => 'garbage', 'image/png'));
})->throws(OcrException::class);

it('normalises tesseract TSV: boxes as ratios, per-word confidence, grouped lines and key/values', function () {
    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
        ."1\t1\t0\t0\t0\t0\t0\t0\t1000\t2000\t-1\t\n"
        ."5\t1\t1\t1\t1\t1\t100\t200\t150\t40\t96.5\tInvoice\n"
        ."5\t1\t1\t1\t1\t2\t260\t200\t60\t40\t91\tNo:\n"
        ."5\t1\t1\t1\t1\t3\t330\t200\t200\t40\t72\tINV-9\n"
        ."5\t1\t2\t1\t1\t1\t100\t400\t120\t40\t88\tTotal\n";

    $page = (new TesseractOcr([]))->pageFromTsv(1, $tsv, 1000, 2000);

    expect($page->words)->toHaveCount(4)
        ->and($page->words[0])->toBe(['text' => 'Invoice', 'bbox' => [0.1, 0.1, 0.15, 0.02], 'confidence' => 0.965])
        ->and(collect($page->lines)->pluck('text')->all())->toBe(['Invoice No: INV-9', 'Total'])
        ->and($page->lines[0]['bbox'])->toBe([0.1, 0.1, 0.43, 0.02])
        ->and($page->keyValues[0])->toMatchArray(['key' => 'Invoice No', 'value' => 'INV-9', 'value_bbox' => [0.33, 0.1, 0.2, 0.02]])
        ->and($page->raw['format'])->toBe('tesseract-tsv');
});

it('runs real tesseract on a 300 DPI page and agrees with poppler on where words are', function () {
    $dir = sys_get_temp_dir().'/eiq-'.uniqid();
    mkdir($dir);
    exec('pdftoppm -r 300 -png '.escapeshellarg(samplePath('03-inv-k-2041-kestrel-3way-match.pdf')).' '.escapeshellarg("$dir/p"));
    $png = glob("$dir/p*.png")[0];
    [$w, $h] = getimagesize($png);
    $bytes = file_get_contents($png);

    $tess = (new TesseractOcr(config('extractiq.ocr.drivers.tesseract')))->recognize(new OcrInput(
        fn () => $bytes, 'image/png', 1, [['page_number' => 1, 'width' => $w, 'height' => $h, 'bytes' => fn () => $bytes]],
    ));
    $poppler = (new PdfTextOcr)->recognize(pdfInput('03-inv-k-2041-kestrel-3way-match.pdf'));
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);

    assertNormalisedShape($tess);
    $t = collect($tess->pages[0]->words)->firstWhere('text', 'INV-K-2041');
    $p = collect($poppler->pages[0]->words)->firstWhere('text', 'INV-K-2041');
    expect($tess->provider)->toBe('tesseract')
        ->and($tess->confidence())->toBeGreaterThan(0.8)
        ->and($tess->text())->toContain('INV-K-2041')
        ->and(abs($t['bbox'][0] - $p['bbox'][0]))->toBeLessThan(0.01)
        ->and(abs($t['bbox'][1] - $p['bbox'][1]))->toBeLessThan(0.01);
})->skip(fn () => ! trim((string) shell_exec('command -v tesseract')) || ! trim((string) shell_exec('command -v pdftoppm')), 'tesseract/poppler not installed');
