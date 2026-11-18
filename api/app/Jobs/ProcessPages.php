<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Pipeline\EventRecorder;
use App\Services\Pipeline\PageSplitter;
use Throwable;

/**
 * First pipeline step: split the original into page images (300 DPI PNG) and record
 * document_pages with width/height. Doesn't change status; hands over to OCR.
 */
class ProcessPages extends PipelineJob
{
    public function step(): string
    {
        return 'pages';
    }

    protected function accepts(): array
    {
        return [DocumentStatus::Received];
    }

    protected function process(Document $document): ?array
    {
        $splitter = app(PageSplitter::class);
        $pages = $splitter->split($document);
        $splitter->persist($document, $pages);

        $this->then(fn () => RunOcr::dispatch($document->id));

        return ['meta' => [
            'pages' => count($pages),
            'dpi' => $pages[0]['dpi'] ?? null,
            'size' => isset($pages[0]) ? "{$pages[0]['width']}×{$pages[0]['height']}" : null,
        ]];
    }

    /** Page images are helpful but not essential: after the last retry, carry on with OCR. */
    protected function onFinalFailure(Document $document, ?Throwable $e): void
    {
        app(EventRecorder::class)->record($document, 'pages', 'skipped', $document->status->value, null,
            attempt: $this->attempts(), error: $e?->getMessage(), meta: ['reason' => 'continuing_without_page_images']);
        RunOcr::dispatch($document->id);
    }
}
