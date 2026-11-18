<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\ExtractFields;
use App\Jobs\RunOcr;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrManager;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrResult;
use App\Services\Pipeline\DocumentIngestor;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
});

function receivedSample($company, string $file, array $meta = []): Document
{
    Queue::fake();
    [$doc] = app(DocumentIngestor::class)->ingest($company, file_get_contents(samplePath($file)), $file, 'application/pdf', $meta);

    return $doc->fresh();
}

it('moves to ocr_done and stores normalised + raw OCR per page, logging provider and duration', function () {
    $doc = receivedSample($this->company, '01-po-1001-kestrel.pdf');
    Queue::fake();

    (new RunOcr($doc->id))->handle(app(EventRecorder::class));

    $doc->refresh();
    $page = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->sole();
    expect($doc->status)->toBe(DocumentStatus::OcrDone)
        ->and($doc->ocr_provider)->toBe('pdftext')
        ->and($page->ocr)->toHaveKeys(['words', 'lines', 'key_values', 'tables'])
        ->and(count($page->ocr['words']))->toBeGreaterThan(50)
        ->and($page->ocr_raw['format'])->toBe('pdftotext-bbox')
        ->and($page->text)->toContain('PO Number: PO-1001');

    $event = $doc->events()->where('step', 'ocr')->sole();
    expect($event->status)->toBe('succeeded')->and($event->to_status)->toBe('ocr_done')
        ->and($event->duration_ms)->not->toBeNull()
        ->and($event->meta['provider'])->toBe('pdftext')
        ->and($event->meta['words'])->toBeGreaterThan(50);
    Queue::assertPushed(ExtractFields::class);
});

it('auto-classifies documents that arrive as unknown and records why', function () {
    $doc = receivedSample($this->company, '02-grn-5001-kestrel.pdf');
    Queue::fake();
    expect($doc->type)->toBe(DocumentType::Unknown);

    (new RunOcr($doc->id))->handle(app(EventRecorder::class));

    $doc->refresh();
    expect($doc->type)->toBe(DocumentType::GoodsReceipt)->and($doc->type_source)->toBe('classifier');
    $meta = $doc->events()->where('step', 'ocr')->sole()->meta;
    expect($meta['classified_as'])->toBe('goods_receipt')->and($meta['classifier_signals'])->not->toBeEmpty();
});

it('does not override a type declared at upload', function () {
    $doc = receivedSample($this->company, '02-grn-5001-kestrel.pdf', ['declared_type' => 'invoice']);
    Queue::fake();

    (new RunOcr($doc->id))->handle(app(EventRecorder::class));

    expect($doc->fresh()->type)->toBe(DocumentType::Invoice);
});

it('keeps the classifier type through extraction unless the model is very sure otherwise', function () {
    ingestSample($this->company, '02-grn-5001-kestrel.pdf');
    $doc = Document::withoutGlobalScopes()->latest('id')->first();

    expect($doc->type)->toBe(DocumentType::GoodsReceipt)->and($doc->type_source)->toBe('classifier');
});

it('retries 3 times with backoff, then marks the document failed with the reason', function () {
    $doc = receivedSample($this->company, '01-po-1001-kestrel.pdf');
    app(OcrManager::class)->extend('broken', fn () => new class implements OcrProvider
    {
        public function recognize(OcrInput $input): OcrResult
        {
            throw new OcrException('Textract ThrottlingException: Rate exceeded');
        }

        public function name(): string
        {
            return 'broken';
        }
    });
    config(['extractiq.ocr.default' => 'broken']);
    $job = new RunOcr($doc->id);

    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60, 300]);
    expect(fn () => $job->handle(app(EventRecorder::class)))->toThrow(OcrException::class);
    expect($doc->fresh()->status)->toBe(DocumentStatus::Received);

    $job->failed(new OcrException('Textract ThrottlingException: Rate exceeded'));

    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Failed)
        ->and($doc->last_error)->toContain('OCR failed: Textract ThrottlingException')
        ->and($doc->events()->where('step', 'ocr')->where('to_status', 'failed')->exists())->toBeTrue();
});

it('lets a reviewer retry a failed document', function () {
    $doc = receivedSample($this->company, '01-po-1001-kestrel.pdf');
    $doc->forceFill(['last_error' => 'OCR failed: boom'])->save();
    $doc->transitionTo(DocumentStatus::Failed);
    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    Queue::fake([]);

    $this->postJson("/api/v1/documents/{$doc->id}/retry")->assertStatus(202);

    expect($doc->fresh()->status)->not->toBe(DocumentStatus::Failed)->and($doc->fresh()->last_error)->toBeNull();
});

it('fails fast on configuration errors instead of burning retries', function () {
    $doc = receivedSample($this->company, '01-po-1001-kestrel.pdf');
    app(OcrManager::class)->extend('nokeys', fn () => new class implements OcrProvider
    {
        public function recognize(OcrInput $input): OcrResult
        {
            throw new OcrException('AWS credentials are not configured for Textract.', retryable: false);
        }

        public function name(): string
        {
            return 'nokeys';
        }
    });
    config(['extractiq.ocr.default' => 'nokeys']);

    (new RunOcr($doc->id))->handle(app(EventRecorder::class)); // non-retryable: handled, not rethrown

    expect($doc->events()->where('step', 'ocr')->where('status', 'failed')->exists())->toBeTrue();
});

it('serves normalised OCR for a page, and raw on request', function () {
    $doc = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');
    Sanctum::actingAs(userWithRole('viewer', $this->company));

    $res = $this->getJson("/api/v1/documents/{$doc->id}/pages/1/ocr")->assertOk()
        ->assertJsonPath('data.attributes.provider', 'pdftext')
        ->assertJsonMissingPath('data.attributes.raw');
    expect($res->json('data.attributes.words.0'))->toHaveKeys(['text', 'bbox', 'confidence'])
        ->and(collect($res->json('data.attributes.key_values'))->firstWhere('key', 'Invoice No')['value'])->toBe('INV-K-2041');

    $this->getJson("/api/v1/documents/{$doc->id}/pages/1/ocr?include=raw")->assertJsonPath('data.attributes.raw.format', 'pdftotext-bbox');
    $this->getJson("/api/v1/documents/{$doc->id}/pages")->assertJsonPath('data.0.attributes.key_value_count', fn ($n) => $n >= 5);
});
