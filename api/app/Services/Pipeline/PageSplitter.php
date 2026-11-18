<?php

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a document into page images stored next to the original:
 *   {company}/{yyyy}/{mm}/{uuid}/page-{n}.png
 * PDFs are rasterised with poppler's pdftoppm at the configured DPI (300).
 * PNG/JPEG uploads are a single page stored as-is; TIFFs are stored as-is
 * (browsers can't render TIFF, so the viewer falls back to the original).
 */
class PageSplitter
{
    /** @return list<array{page_number: int, image_path: string, width: int, height: int, dpi: ?int}> */
    public function split(Document $document): array
    {
        $bytes = $document->fileContents();
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $document->storage_path);
        $disk = Storage::disk($document->storage_disk);

        if ($document->mime_type !== 'application/pdf') {
            $size = @getimagesizefromstring($bytes);
            if (! $size) {
                throw new RuntimeException('Unreadable image file.');
            }
            $ext = match ($document->mime_type) {
                'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/tiff' => 'tiff', default => 'bin'
            };
            $path = "$base/page-1.$ext";
            $disk->put($path, $bytes, ['ContentType' => $document->mime_type]);

            return [['page_number' => 1, 'image_path' => $path, 'width' => $size[0], 'height' => $size[1], 'dpi' => null]];
        }

        $dpi = (int) config('extractiq.pages.dpi', 300);
        $dir = storage_path('app/tmp/pages-'.Str::uuid());
        File::ensureDirectoryExists($dir);
        try {
            File::put("$dir/in.pdf", $bytes);
            $result = Process::timeout(300)->run([config('extractiq.pages.pdftoppm', 'pdftoppm'), '-r', (string) $dpi, '-png', "$dir/in.pdf", "$dir/page"]);
            if (! $result->successful()) {
                throw new RuntimeException('pdftoppm failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            // pdftoppm names pages page-1.png … or page-01.png when there are 10+; sort numerically.
            $files = glob("$dir/page-*.png") ?: [];
            usort($files, fn ($a, $b) => $this->pageNo($a) <=> $this->pageNo($b));
            if (! $files) {
                throw new RuntimeException('pdftoppm produced no pages.');
            }

            $pages = [];
            foreach ($files as $file) {
                $n = $this->pageNo($file);
                [$w, $h] = getimagesize($file) ?: [0, 0];
                $path = "$base/page-$n.png";
                $disk->put($path, File::get($file), ['ContentType' => 'image/png']);
                $pages[] = ['page_number' => $n, 'image_path' => $path, 'width' => $w, 'height' => $h, 'dpi' => $dpi];
            }

            return $pages;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Upsert page rows (keeping OCR text if a later step already wrote it) and drop stale ones.
     *
     * @param  list<array{page_number: int, image_path: string, width: int, height: int, dpi: ?int}>  $pages
     */
    public function persist(Document $document, array $pages): void
    {
        foreach ($pages as $p) {
            DocumentPage::withoutGlobalScopes()->updateOrCreate(
                ['document_id' => $document->id, 'page_number' => $p['page_number']],
                ['company_id' => $document->company_id, 'image_disk' => $document->storage_disk] + $p,
            );
        }
        DocumentPage::withoutGlobalScopes()->where('document_id', $document->id)
            ->where('page_number', '>', count($pages))->delete();
        $document->forceFill(['page_count' => count($pages)])->saveQuietly();
    }

    private function pageNo(string $file): int
    {
        return (int) preg_replace('/\D/', '', basename($file, '.png'));
    }
}
