<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\Ocr\DocumentClassifier;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrManager;
use App\Services\Pipeline\EventRecorder;
use Throwable;

/**
 * OCR step: received → ocr_done. Stores normalised OCR (words/lines/key_values/tables)
 * and the raw provider payload per page, auto-classifies documents that arrived as
 * "unknown", and after the last retry moves the document to "failed" with the reason.
 */
class RunOcr extends PipelineJob
{
    public int $timeout = 600; // Textract async polling can take a few minutes for long PDFs

    public function step(): string
    {
        return 'ocr';
    }

    protected function accepts(): array
    {
        return [DocumentStatus::Received];
    }

    protected function process(Document $document): ?array
    {
        $provider = app(OcrManager::class)->forCompany($document->company_id);
        $input = OcrInput::fromDocument($document, onJobStarted: function (string $job) use ($document) {
            $document->forceFill(['ocr_job_id' => $job])->saveQuietly();
        });
        $result = $provider->recognize($input);

        foreach ($result->pages as $page) {
            $text = $page->plainText ?? $page->text();
            DocumentPage::withoutGlobalScopes()->updateOrCreate(
                ['document_id' => $document->id, 'page_number' => $page->number],
                [
                    'company_id' => $document->company_id,
                    'text' => $text,
                    'ocr' => $page->normalised(),
                    'ocr_raw' => $page->raw,
                    'char_count' => mb_strlen($text),
                    'confidence' => $page->confidence(),
                    'ocr_provider' => $result->provider,
                ],
            );
        }

        $document->fill([
            'ocr_text' => $result->text(),
            'ocr_provider' => $result->provider,
            'ocr_confidence' => $result->confidence(),
            'ocr_job_id' => null,
            'page_count' => $document->page_count ?: $result->pageCount(),
        ]);

        $classification = null;
        if ($document->type === DocumentType::Unknown && ! $document->declared_type) {
            $classification = app(DocumentClassifier::class)->classify($result->pages[0]->plainText ?? $result->pages[0]->text());
            if ($classification['type']) {
                $document->type = DocumentType::from($classification['type']);
                $document->type_source = 'classifier';
            }
        }

        $document->transitionTo(DocumentStatus::OcrDone);
        $this->then(fn () => ExtractFields::dispatch($document->id));

        return ['meta' => array_filter([
            'provider' => $result->provider,
            'api' => $result->meta['api'] ?? null,
            'mode' => $result->meta['mode'] ?? null,
            'pages' => $result->pageCount(),
            'words' => array_sum(array_map(fn ($p) => count($p->words), $result->pages)),
            'key_values' => array_sum(array_map(fn ($p) => count($p->keyValues), $result->pages)),
            'tables' => array_sum(array_map(fn ($p) => count($p->tables), $result->pages)),
            'confidence' => $result->confidence(),
            'chars' => mb_strlen($result->text()),
            'classified_as' => $classification['type'] ?? null,
            'classifier_confidence' => $classification['confidence'] ?? null,
            'classifier_signals' => $classification ? array_slice($classification['signals'], 0, 6) : null,
            'llm_classification_pending' => $classification && ! $classification['type'] ? true : null,
        ], fn ($v) => $v !== null)];
    }

    /** After the last retry: status "failed" with the reason, so the document is visible and retryable. */
    protected function onFinalFailure(Document $document, ?Throwable $e): void
    {
        if ($document->status !== DocumentStatus::Received) {
            return;
        }
        $reason = $e?->getMessage() ?: 'OCR failed';
        $document->forceFill(['last_error' => mb_substr("OCR failed: $reason", 0, 2000)]);
        $document->transitionTo(DocumentStatus::Failed);
        app(EventRecorder::class)->record($document, 'ocr', 'failed', DocumentStatus::Received->value, DocumentStatus::Failed->value,
            attempt: $this->attempts(), error: $reason, meta: ['final' => true, 'provider' => config('extractiq.ocr.default')]);
    }
}
