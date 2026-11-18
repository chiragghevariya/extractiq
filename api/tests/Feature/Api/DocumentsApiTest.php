<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->user = userWithRole('reviewer', $this->company);
    Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Kestrel Industrial Parts LLC']);
    Sanctum::actingAs($this->user);
});

it('uploads PDFs and runs the pipeline', function () {
    $file = UploadedFile::fake()->createWithContent('inv.pdf', file_get_contents(samplePath('03-inv-k-2041-kestrel-3way-match.pdf')));

    $res = $this->post('/api/v1/documents', ['files' => [$file]], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('meta.created', 1)
        ->assertJsonPath('data.0.type', 'documents');

    $doc = Document::find($res->json('data.0.id'));
    expect($doc->status)->toBe(DocumentStatus::Validated)->and($doc->company_id)->toBe($this->company->id);
    Storage::disk('local')->assertExists($doc->storage_path);
});

it('rejects unsupported file types', function () {
    $this->post('/api/v1/documents', ['files' => [UploadedFile::fake()->create('evil.exe', 10)]], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/files/0');
});

it('lists documents with filters, search, sort and pagination', function () {
    Document::factory()->count(3)->status(DocumentStatus::NeedsReview)->create(['company_id' => $this->company->id]);
    Document::factory()->status(DocumentStatus::Pushed)->create(['company_id' => $this->company->id, 'document_number' => 'FIND-ME-1']);

    $this->getJson('/api/v1/documents?filter[status]=needs_review&page[size]=2')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.page.total', 3)->assertJsonPath('meta.page.last', 2);
    $this->getJson('/api/v1/documents?filter[q]=find-me')->assertJsonCount(1, 'data')->assertJsonPath('data.0.attributes.document_number', 'FIND-ME-1');
    $this->getJson('/api/v1/documents?sort=bogus')->assertStatus(422);
});

it('isolates tenants', function () {
    $other = Document::factory()->create(['company_id' => Company::factory()->create()->id]);
    Document::factory()->create(['company_id' => $this->company->id]);

    $this->getJson('/api/v1/documents')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/documents/{$other->id}")->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $this->patchJson("/api/v1/documents/{$other->id}", ['document_number' => 'X'])->assertNotFound();
});

it('shows a document with included lines, vendor and OCR text', function () {
    $doc = Document::factory()->withLines([['A-1', 2, 5000]])->create(['company_id' => $this->company->id, 'ocr_text' => 'raw text']);

    $this->getJson("/api/v1/documents/{$doc->id}")
        ->assertOk()
        ->assertJsonPath('data.attributes.ocr_text', 'raw text')
        ->assertJsonPath('data.relationships.lines.data.0.type', 'document-lines')
        ->assertJsonPath('included.0.type', 'document-lines')
        ->assertJsonPath('included.0.attributes.amount_minor', 10000);
});

it('applies reviewer corrections, re-validates and logs the change', function () {
    $doc = Document::factory()->status(DocumentStatus::NeedsReview)->withLines([['A-1', 2, 5000]])
        ->create(['company_id' => $this->company->id, 'total_minor' => 99999, 'po_number' => 'PO-1']);

    $this->patchJson("/api/v1/documents/{$doc->id}", ['total_minor' => 10800, 'vendor_name' => 'Kestrel Industrial Parts LLC'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'validated')
        ->assertJsonPath('data.attributes.validation.errors', []);

    $event = $doc->events()->where('step', 'review')->first();
    expect($event->user_id)->toBe($this->user->id)
        ->and($event->meta['diff']['fields']['total_minor'])->toBe(['before' => 99999, 'after' => 10800])
        ->and($doc->fresh()->vendor_id)->not->toBeNull();
});

it('replaces line items on update', function () {
    $doc = Document::factory()->status(DocumentStatus::NeedsReview)->withLines([['A-1', 2, 5000]])->create(['company_id' => $this->company->id]);

    $this->patchJson("/api/v1/documents/{$doc->id}", ['lines' => [
        ['sku' => 'N-1', 'description' => 'New', 'quantity' => 1, 'unit_price_minor' => 10000, 'amount_minor' => 10000],
    ]])->assertOk();

    expect($doc->fresh()->lines)->toHaveCount(1)->and($doc->fresh()->lines->first()->sku)->toBe('N-1');
});

it('blocks edits once approved', function () {
    $doc = Document::factory()->status(DocumentStatus::Approved)->create(['company_id' => $this->company->id]);

    $this->patchJson("/api/v1/documents/{$doc->id}", ['document_number' => 'X'])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/status');
});

it('approves a validated document and blocks approval with errors', function () {
    $po = Document::factory()->type(DocumentType::PurchaseOrder)->status(DocumentStatus::Validated)->create(['company_id' => $this->company->id, 'validation' => ['errors' => [], 'warnings' => []]]);
    $bad = Document::factory()->status(DocumentStatus::NeedsReview)->create(['company_id' => $this->company->id, 'validation' => ['errors' => [['code' => 'x']], 'warnings' => []]]);

    $this->postJson("/api/v1/documents/{$po->id}/approve")->assertOk()->assertJsonPath('data.attributes.status', 'approved');
    $this->postJson("/api/v1/documents/{$bad->id}/approve")->assertStatus(422);
    expect($po->fresh()->approved_by)->toBe($this->user->id);
});

it('overrides a match exception with a reason and pushes (admin only by default)', function () {
    $doc = Document::factory()->status(DocumentStatus::MatchException)->withLines([['A-1', 1, 10000]])->create(['company_id' => $this->company->id]);
    fakeMatch($doc);

    $this->postJson("/api/v1/documents/{$doc->id}/override-match", ['reason' => 'x'])->assertStatus(422);
    $this->postJson("/api/v1/documents/{$doc->id}/override-match", ['reason' => 'Approved by finance lead'])->assertForbidden();

    Sanctum::actingAs(userWithRole('admin', $this->company));
    $this->postJson("/api/v1/documents/{$doc->id}/override-match", ['reason' => 'Approved by finance lead'])->assertOk();

    expect($doc->fresh()->status)->toBe(DocumentStatus::Pushed)
        ->and($doc->latestMatch()->first()->result)->toBe('accepted');
});

it('retries the waiting step', function () {
    $doc = Document::factory()->status(DocumentStatus::PushFailed)->create(['company_id' => $this->company->id]);

    $this->postJson("/api/v1/documents/{$doc->id}/retry")->assertStatus(202)->assertJsonPath('meta.queued_step', 'push');
    expect($doc->fresh()->status)->toBe(DocumentStatus::Pushed);
});

it('returns the audit trail and match history', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf');

    $this->getJson("/api/v1/documents/{$doc->id}/events")->assertOk()
        ->assertJsonPath('data.0.attributes.step', 'ingest')
        ->assertJsonPath('data.1.attributes.step', 'pages')
        ->assertJsonPath('data.2.attributes.step', 'ocr')
        ->assertJsonStructure(['data' => [['attributes' => ['duration_ms', 'status', 'error', 'attempt']]]]);
    $this->getJson("/api/v1/documents/{$doc->id}/matches")->assertOk()->assertJsonCount(0, 'data');
});

it('streams the original file', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf');

    $this->get("/api/v1/documents/{$doc->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('enforces roles', function () {
    $viewer = userWithRole('viewer', $this->company);
    Sanctum::actingAs($viewer);
    $doc = Document::factory()->status(DocumentStatus::Validated)->create(['company_id' => $this->company->id]);

    $this->getJson('/api/v1/documents')->assertOk();
    $this->postJson("/api/v1/documents/{$doc->id}/approve")->assertForbidden()->assertJsonPath('errors.0.code', 'forbidden');
    $this->deleteJson("/api/v1/documents/{$doc->id}")->assertForbidden();
});

it('lets admins soft-delete documents', function () {
    Sanctum::actingAs(userWithRole('admin', $this->company));
    $doc = Document::factory()->create(['company_id' => $this->company->id]);

    $this->deleteJson("/api/v1/documents/{$doc->id}")->assertNoContent();
    expect(Document::withTrashed()->find($doc->id)->trashed())->toBeTrue();
});

it('returns OCR pages', function () {
    $doc = ingestSample($this->company, '01-po-1001-kestrel.pdf');

    $this->getJson("/api/v1/documents/{$doc->id}/pages")->assertOk()
        ->assertJsonPath('data.0.type', 'document-pages')
        ->assertJsonPath('data.0.attributes.page_number', 1);
});
