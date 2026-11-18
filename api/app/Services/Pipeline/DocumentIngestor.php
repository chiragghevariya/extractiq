<?php

namespace App\Services\Pipeline;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\ProcessPages;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single entry point for new files (upload, API or email). Deduplicates by SHA-256
 * per tenant, stores the original at {company}/{yyyy}/{mm}/{uuid}.{ext} on the
 * documents disk (S3/MinIO in production), writes the "ingest" event and queues
 * page splitting (which then queues OCR).
 */
class DocumentIngestor
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array{source?: string, email_from?: ?string, email_subject?: ?string, email_message_id?: ?string, user_id?: ?int, declared_type?: ?string, received_at?: mixed}  $meta
     * @return array{0: Document, 1: bool} [document, created]
     */
    public function ingest(Company $company, string $bytes, string $filename, string $mimeType, array $meta = []): array
    {
        $sha = hash('sha256', $bytes);

        $existing = Document::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $company->id)->where('sha256', $sha)->first();
        if ($existing) {
            $this->events->record($existing, 'ingest', 'skipped', meta: ['reason' => 'duplicate_file', 'filename' => $filename, 'source' => $meta['source'] ?? 'upload'], userId: $meta['user_id'] ?? null);

            return [$existing, false];
        }

        $disk = config('extractiq.documents_disk');
        $path = self::storagePath($company, $filename, $mimeType);
        Storage::disk($disk)->put($path, $bytes, ['ContentType' => $mimeType]);

        $doc = DB::transaction(function () use ($company, $bytes, $filename, $mimeType, $meta, $disk, $path, $sha) {
            $doc = Document::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'source' => $meta['source'] ?? 'upload',
                'type' => DocumentType::tryFrom((string) ($meta['declared_type'] ?? '')) ?? DocumentType::Unknown,
                'declared_type' => DocumentType::tryFrom((string) ($meta['declared_type'] ?? ''))?->value,
                'uploaded_by' => $meta['user_id'] ?? null,
                'status' => DocumentStatus::Received,
                'original_filename' => mb_substr($filename, 0, 255),
                'mime_type' => $mimeType,
                'size_bytes' => strlen($bytes),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'sha256' => $sha,
                'email_from' => $meta['email_from'] ?? null,
                'email_subject' => isset($meta['email_subject']) ? mb_substr($meta['email_subject'], 0, 255) : null,
                'email_message_id' => $meta['email_message_id'] ?? null,
                'received_at' => $meta['received_at'] ?? now(),
            ]);
            $this->events->record($doc, 'ingest', 'succeeded', null, DocumentStatus::Received->value,
                meta: ['source' => $doc->source, 'filename' => $filename, 'size_bytes' => $doc->size_bytes], userId: $meta['user_id'] ?? null);

            return $doc;
        });

        ProcessPages::dispatch($doc->id)->afterCommit();

        return [$doc, true];
    }

    /** {company}/{yyyy}/{mm}/{uuid}.{ext} */
    public static function storagePath(Company $company, string $filename, string $mimeType): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'], true)) {
            $ext = match ($mimeType) {
                'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/tiff' => 'tiff', default => 'bin',
            };
        }

        return sprintf('%s/%s/%s.%s', $company->slug, now()->format('Y/m'), Str::uuid(), $ext);
    }
}
