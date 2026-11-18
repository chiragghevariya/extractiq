<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessPages;
use App\Jobs\RunOcr;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\Pipeline\DocumentIngestor;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
});

function receivedDoc($company, string $bytes, string $name, string $mime): Document
{
    Queue::fake();
    [$doc] = app(DocumentIngestor::class)->ingest($company, $bytes, $name, $mime);
    Queue::fake([]); // stop faking for the job under test

    return $doc->fresh();
}

function pngBytes(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

it('splits a PDF into 300 DPI PNG pages with width and height', function () {
    $doc = receivedDoc($this->company, file_get_contents(samplePath('01-po-1001-kestrel.pdf')), 'po.pdf', 'application/pdf');
    Queue::fake();

    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));

    $page = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->sole();
    $base = preg_replace('/\.pdf$/', '', $doc->storage_path);
    expect($page->page_number)->toBe(1)
        ->and($page->dpi)->toBe(300)
        ->and($page->width)->toBeIn([2480, 2481])   // A4 (595.28 × 841.89 pt) at 300 DPI
        ->and($page->height)->toBeIn([3508, 3509])
        ->and($page->image_path)->toBe("$base/page-1.png")
        ->and($doc->fresh()->page_count)->toBe(1);
    Storage::disk('local')->assertExists($page->image_path);
    expect(getimagesizefromstring(Storage::disk('local')->get($page->image_path))['mime'])->toBe('image/png');
    Queue::assertPushed(RunOcr::class, fn ($j) => $j->documentId === $doc->id);
})->skip(fn () => ! trim((string) shell_exec('command -v '.escapeshellarg(config('extractiq.pages.pdftoppm', 'pdftoppm')))), 'pdftoppm (poppler) not installed');

it('orders many pages numerically and records an audited event', function () {
    $doc = receivedDoc($this->company, file_get_contents(samplePath('01-po-1001-kestrel.pdf')), 'po.pdf', 'application/pdf');
    $png = pngBytes(1275, 1650);
    // Pretend pdftoppm rendered 12 pages (it zero-pads to page-01 … page-12).
    Process::fake(function (PendingProcess $process) use ($png) {
        $prefix = end($process->command);
        foreach (range(1, 12) as $n) {
            file_put_contents(sprintf('%s-%02d.png', $prefix, $n), $png);
        }

        return Process::result();
    });
    Queue::fake();

    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));

    $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('page_number')->get();
    expect($pages->pluck('page_number')->all())->toBe(range(1, 12))
        ->and($pages->last()->image_path)->toEndWith('/page-12.png')
        ->and($pages->first()->width)->toBe(1275);
    Process::assertRan(fn ($p) => in_array('-r', $p->command, true) && in_array('300', $p->command, true) && in_array('-png', $p->command, true));

    $event = $doc->events()->where('step', 'pages')->sole();
    expect($event->status)->toBe('succeeded')->and($event->meta['pages'])->toBe(12)->and($event->duration_ms)->not->toBeNull();
});

it('stores an image upload as a single page', function () {
    $doc = receivedDoc($this->company, pngBytes(800, 600), 'scan.png', 'image/png');
    Queue::fake();

    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));

    $page = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->sole();
    expect($page->width)->toBe(800)->and($page->height)->toBe(600)->and($page->image_path)->toEndWith('/page-1.png');
});

it('keeps page images when OCR later writes the text', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf'); // full sync pipeline
    $page = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->sole();

    expect($page->text)->toContain('PO-1001')->and($page->image_path)->not->toBeNull()->and($page->width)->toBeIn([2480, 2481]);
})->skip(fn () => ! trim((string) shell_exec('command -v pdftoppm')), 'pdftoppm (poppler) not installed');

it('retries a failed split, then carries on to OCR without page images', function () {
    $doc = receivedDoc($this->company, file_get_contents(samplePath('01-po-1001-kestrel.pdf')), 'po.pdf', 'application/pdf');
    Process::fake(['*' => Process::result(errorOutput: 'Syntax Error: broken PDF', exitCode: 1)]);
    Queue::fake();

    expect(fn () => (new ProcessPages($doc->id))->handle(app(EventRecorder::class)))->toThrow(RuntimeException::class, 'pdftoppm failed');
    expect($doc->events()->where('step', 'pages')->where('status', 'failed')->exists())->toBeTrue();

    (new ProcessPages($doc->id))->failed(new RuntimeException('pdftoppm failed'));

    Queue::assertPushed(RunOcr::class);
    expect($doc->events()->where('step', 'pages')->where('status', 'skipped')->exists())->toBeTrue()
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Received);
});

it('is idempotent: re-running replaces pages without duplicating them', function () {
    $doc = receivedDoc($this->company, pngBytes(200, 100), 'scan.png', 'image/png');
    Queue::fake();
    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));
    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));

    expect(DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->count())->toBe(1);
});

it('serves page images to the tenant only', function () {
    $doc = receivedDoc($this->company, pngBytes(320, 200), 'scan.png', 'image/png');
    Queue::fake();
    (new ProcessPages($doc->id))->handle(app(EventRecorder::class));

    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $this->get("/api/v1/documents/{$doc->id}/pages/1/image")->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->getJson("/api/v1/documents/{$doc->id}/pages")->assertJsonPath('data.0.attributes.width', 320)->assertJsonPath('data.0.attributes.has_image', true);
    $this->getJson("/api/v1/documents/{$doc->id}/pages/9/image")->assertNotFound();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs(userWithRole('viewer', Company::factory()->create()));
    $this->get("/api/v1/documents/{$doc->id}/pages/1/image", ['Accept' => 'application/json'])->assertNotFound();
});
