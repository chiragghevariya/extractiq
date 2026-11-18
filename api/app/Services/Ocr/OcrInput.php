<?php

namespace App\Services\Ocr;

use App\Models\Document;
use App\Models\DocumentPage;
use Closure;
use Illuminate\Support\Facades\Storage;

/** Everything an OCR provider may need about one document. File bytes are loaded lazily. */
final class OcrInput
{
    /**
     * @param  Closure(): string  $bytes
     * @param  list<array{page_number: int, width: ?int, height: ?int, bytes: Closure(): string}>  $pageImages  300 DPI renders from ProcessPages
     * @param  ?array{bucket: string, key: string}  $s3  where the original lives (Textract async reads from S3)
     */
    public function __construct(
        public readonly Closure $bytes,
        public readonly string $mimeType,
        public readonly int $pageCount = 1,
        public readonly array $pageImages = [],
        public readonly string $documentType = 'unknown',
        public readonly ?array $s3 = null,
        public readonly ?string $resumeJobId = null,
        /** Called with "api:jobId" when an async provider job starts, so a retry can resume polling it. */
        public readonly ?Closure $onJobStarted = null,
    ) {}

    public static function fromDocument(Document $document, ?Closure $onJobStarted = null): self
    {
        $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $document->id)
            ->whereNotNull('image_path')->orderBy('page_number')->get();

        $disk = $document->storage_disk;
        $s3 = config("filesystems.disks.$disk.driver") === 's3'
            ? ['bucket' => (string) config("filesystems.disks.$disk.bucket"), 'key' => $document->storage_path]
            : null;

        return new self(
            bytes: fn () => $document->fileContents(),
            mimeType: $document->mime_type,
            pageCount: max(1, (int) $document->page_count),
            pageImages: $pages->filter(fn (DocumentPage $p) => ! str_ends_with((string) $p->image_path, '.tiff'))
                ->map(fn (DocumentPage $p) => [
                    'page_number' => $p->page_number,
                    'width' => $p->width,
                    'height' => $p->height,
                    'bytes' => fn () => Storage::disk($p->image_disk ?? $disk)->get($p->image_path),
                ])->values()->all(),
            documentType: $document->declared_type ?? $document->type->value,
            s3: $s3,
            resumeJobId: $document->ocr_job_id,
            onJobStarted: $onJobStarted,
        );
    }
}
