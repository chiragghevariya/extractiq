<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Short-lived links to an original file or a page image, so the browser never needs a public
 * bucket or a long-lived URL. On S3 (or MinIO) it's a presigned S3 URL; on the local disk a
 * Laravel signed route. Both expire after `extractiq.security.signed_url_ttl` seconds.
 */
class FileLinkController extends Controller
{
    /** GET /documents/{id}/file-url[?page=N] (authenticated, tenant-scoped). */
    public function link(Request $request, Document $document)
    {
        $page = $request->integer('page') ?: null;
        [$disk, $path, $mime, $name] = $this->target($document, $page);
        $ttl = (int) config('extractiq.security.signed_url_ttl', 300);
        $expires = now()->addSeconds($ttl);

        $url = config("filesystems.disks.$disk.driver") === 's3'
            ? Storage::disk($disk)->temporaryUrl($path, $expires, [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => 'inline; filename="'.addslashes($name).'"',
            ])
            : URL::temporarySignedRoute('files.signed', $expires, array_filter(['uuid' => $document->uuid, 'page' => $page]));

        return response()->json(['data' => ['type' => 'file-links', 'id' => $document->uuid.($page ? "-p$page" : ''), 'attributes' => [
            'url' => $url, 'expires_at' => $expires->toIso8601String(), 'mime_type' => $mime,
        ]]]);
    }

    /** GET /files/{uuid}?page=N&expires=…&signature=… (no session: the signature is the credential). */
    public function show(Request $request, string $uuid)
    {
        $document = Document::withoutGlobalScope('company')->where('uuid', $uuid)->firstOrFail();
        [$disk, $path, $mime, $name] = $this->target($document, $request->integer('page') ?: null);

        return Storage::disk($disk)->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} disk, path, mime, filename */
    private function target(Document $document, ?int $page): array
    {
        if ($page === null) {
            return [$document->storage_disk, $document->storage_path, (string) $document->mime_type, (string) $document->original_filename];
        }
        $p = DocumentPage::withoutGlobalScopes()->where('document_id', $document->id)->where('page_number', $page)->whereNotNull('image_path')->firstOrFail();

        $ext = strtolower(pathinfo($p->image_path, PATHINFO_EXTENSION)) ?: 'png';
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'][$ext] ?? 'image/png';

        return [$p->image_disk ?? $document->storage_disk, $p->image_path, $mime, "page-$page.$ext"];
    }
}
