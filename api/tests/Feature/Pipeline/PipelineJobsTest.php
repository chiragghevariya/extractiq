<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExtractFields;
use App\Jobs\ProcessPages;
use App\Jobs\PushToErp;
use App\Jobs\RunOcr;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentPage;
use App\Models\Vendor;
use App\Services\Pipeline\DocumentIngestor;
use App\Services\Pipeline\DocumentWorkflow;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->company = demoCompany();
    foreach (['Kestrel Industrial Parts LLC', 'Brightline Office Supply Co.', 'Harbor & Pine Packaging Ltd'] as $name) {
        Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => $name]);
    }
    $this->workflow = app(DocumentWorkflow::class);
    $this->approve = fn (Document $d) => $this->workflow->approve($d->fresh(), null);
});

it('runs OCR → extract → validate and records every step', function () {
    $doc = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');

    expect($doc->status)->toBe(DocumentStatus::Validated)
        ->and($doc->document_number)->toBe('INV-K-2041')
        ->and($doc->total_minor)->toBe(61560)
        ->and($doc->vendor_id)->not->toBeNull()
        ->and($doc->lines)->toHaveCount(3);

    $events = DocumentEvent::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('id')->get();
    expect($events->pluck('step')->all())->toBe(['ingest', 'pages', 'ocr', 'extract', 'validate'])
        ->and($events->where('step', '!=', 'ingest')->every(fn ($e) => $e->status === 'succeeded' && $e->duration_ms !== null))->toBeTrue()
        ->and($events->firstWhere('step', 'ocr')->to_status)->toBe('ocr_done');
});

it('runs a full 3-way match and pushes to the ERP', function () {
    ($this->approve)(ingestSample($this->company, '01-po-1001-kestrel.pdf'));
    ($this->approve)(ingestSample($this->company, '02-grn-5001-kestrel.pdf'));
    $invoice = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');
    ($this->approve)($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe(DocumentStatus::Pushed)
        ->and($invoice->erp_connector)->toBe('mock')
        ->and($invoice->erp_ref)->toStartWith('BILL-')
        ->and($invoice->latestMatch->kind)->toBe('three_way')
        ->and($invoice->latestMatch->result)->toBe('matched');
});

it('raises a match exception and resolves it when the PO arrives later', function () {
    $invoice = ingestSample($this->company, '05-inv-bl-7781-brightline-2way-match.pdf');
    ($this->approve)($invoice);
    expect($invoice->fresh()->status)->toBe(DocumentStatus::MatchException);

    // Approving the PO re-queues matching for waiting invoices.
    ($this->approve)(ingestSample($this->company, '04-po-1002-brightline.pdf'));

    expect($invoice->fresh()->status)->toBe(DocumentStatus::Pushed);
});

it('sends documents with arithmetic errors to review', function () {
    $doc = ingestSample($this->company, '12-inv-bl-7790-brightline-total-mismatch.pdf');

    expect($doc->status)->toBe(DocumentStatus::NeedsReview)
        ->and(collect($doc->validation['errors'])->pluck('code'))->toContain('total_math');
});

it('deduplicates identical files per tenant', function () {
    $bytes = file_get_contents(samplePath('01-po-1001-kestrel.pdf'));
    $ingestor = app(DocumentIngestor::class);
    [$a, $createdA] = $ingestor->ingest($this->company, $bytes, 'a.pdf', 'application/pdf');
    [$b, $createdB] = $ingestor->ingest($this->company, $bytes, 'b.pdf', 'application/pdf');

    expect($createdA)->toBeTrue()->and($createdB)->toBeFalse()->and($b->id)->toBe($a->id)
        ->and(Document::withoutGlobalScopes()->count())->toBe(1);
});

it('is idempotent when a step is dispatched twice', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf');
    $before = $doc->fresh()->updated_at;

    RunOcr::dispatchSync($doc->id);
    ExtractFields::dispatchSync($doc->id);

    expect($doc->fresh()->status)->toBe(DocumentStatus::Validated)
        ->and(DocumentEvent::withoutGlobalScopes()->where('document_id', $doc->id)->where('status', 'skipped')->count())->toBe(2)
        ->and($doc->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('queues page splitting on the pipeline queue with retries and backoff', function () {
    Queue::fake();
    [$doc] = app(DocumentIngestor::class)->ingest($this->company, file_get_contents(samplePath('01-po-1001-kestrel.pdf')), 'po.pdf', 'application/pdf');

    Queue::assertPushedOn('pipeline', ProcessPages::class, fn ($job) => $job->documentId === $doc->id && $job->tries === 3 && $job->backoff() === [10, 60, 300]);
});

it('auto-approves validated documents when configured', function () {
    config(['extractiq.pipeline.auto_approve_validated' => true]);
    $po = ingestSample($this->company, '04-po-1002-brightline.pdf');

    expect($po->status)->toBe(DocumentStatus::Approved)->and($po->approved_by)->toBeNull();
});

it('stores one document_pages row per OCR page and replaces them on re-run', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf');
    $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->get();

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->page_number)->toBe(1)
        ->and($pages[0]->text)->toContain('PO Number: PO-1001')
        ->and($pages[0]->char_count)->toBeGreaterThan(100)
        ->and($pages[0]->ocr_provider)->toBe('pdftext')
        ->and($pages[0]->company_id)->toBe($this->company->id);
});
