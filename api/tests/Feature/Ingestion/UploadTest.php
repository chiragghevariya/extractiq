<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\ProcessPages;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->user = userWithRole('reviewer', $this->company);
    $this->pdf = fn (string $name = 'inv.pdf', string $sample = '03-inv-k-2041-kestrel-3way-match.pdf') => UploadedFile::fake()->createWithContent($name, file_get_contents(samplePath($sample)));
});

/** A valid 1×1 8-bit greyscale baseline TIFF. */
function tinyTiff(): string
{
    $entries = [[256, 3, 1, 1], [257, 3, 1, 1], [258, 3, 1, 8], [259, 3, 1, 1], [262, 3, 1, 1], [273, 4, 1, 110], [278, 3, 1, 1], [279, 4, 1, 1]];
    $ifd = pack('v', count($entries));
    foreach ($entries as [$tag, $type, $count, $value]) {
        $ifd .= pack('vvVV', $tag, $type, $count, $value);
    }

    return 'II*'."\0".pack('V', 8).$ifd.pack('V', 0)."\x80";
}

function postFiles($test, array $files, array $extra = [])
{
    return $test->post('/api/v1/documents', ['files' => $files] + $extra, ['Accept' => 'application/json']);
}

it('stores the original at {company}/{yyyy}/{mm}/{uuid}.{ext} and creates a received document', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    $res = postFiles($this, [($this->pdf)()])->assertCreated()->assertJsonPath('data.0.attributes.status', 'received');

    $doc = Document::find($res->json('data.0.id'));
    expect($doc->status)->toBe(DocumentStatus::Received)
        ->and($doc->storage_path)->toMatch('#^demo/'.now()->format('Y/m').'/[0-9a-f-]{36}\.pdf$#')
        ->and($doc->sha256)->toBe(hash('sha256', file_get_contents(samplePath('03-inv-k-2041-kestrel-3way-match.pdf'))))
        ->and($doc->uploaded_by)->toBe($this->user->id);
    Storage::disk('local')->assertExists($doc->storage_path);
    Queue::assertPushed(ProcessPages::class, fn ($job) => $job->documentId === $doc->id);
});

it('uploads several files at once and reports each', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    postFiles($this, [($this->pdf)('a.pdf', '01-po-1001-kestrel.pdf'), ($this->pdf)('b.pdf', '04-po-1002-brightline.pdf')])
        ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('meta.created', 2);
});

it('marks browser uploads as "upload" and token clients as "api"', function () {
    Queue::fake();
    $this->actingAs($this->user, 'web'); // Sanctum SPA (session) request
    $web = postFiles($this, [($this->pdf)('web.pdf', '01-po-1001-kestrel.pdf')])->assertCreated();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($this->user); // personal access token
    $api = postFiles($this, [($this->pdf)('api.pdf', '04-po-1002-brightline.pdf')])->assertCreated();

    expect(Document::find($web->json('data.0.id'))->source)->toBe('upload')
        ->and(Document::find($api->json('data.0.id'))->source)->toBe('api');
});

it('accepts PDF, PNG, JPG and TIFF and rejects other types and files over 20 MB', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    foreach (['scan.png' => 'image/png', 'scan.jpg' => 'image/jpeg'] as $name => $mime) {
        postFiles($this, [UploadedFile::fake()->image($name, 400, 300)])->assertCreated();
    }
    postFiles($this, [UploadedFile::fake()->createWithContent('scan.tiff', tinyTiff())])->assertCreated();
    postFiles($this, [UploadedFile::fake()->create('notes.docx', 10)])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/files/0');
    postFiles($this, [UploadedFile::fake()->create('huge.pdf', 20 * 1024 + 1, 'application/pdf')])->assertStatus(422);
});

it('keeps a type declared at upload even if the model guesses otherwise', function () {
    Sanctum::actingAs($this->user);

    $res = postFiles($this, [($this->pdf)()], ['type' => 'purchase_order'])->assertCreated();

    $doc = Document::find($res->json('data.0.id'));
    expect($doc->declared_type)->toBe('purchase_order')
        ->and($doc->type)->toBe(DocumentType::PurchaseOrder)          // the sample is really an invoice
        ->and($doc->status)->not->toBe(DocumentStatus::Received);     // pipeline still ran
});

it('detects duplicates per company by SHA-256', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    $first = postFiles($this, [($this->pdf)('one.pdf')])->assertCreated();
    $again = postFiles($this, [($this->pdf)('renamed-copy.pdf')])->assertCreated()
        ->assertJsonPath('meta.created', 0)
        ->assertJsonPath('meta.duplicates.0', $first->json('data.0.id'));

    expect($again->json('data.0.id'))->toBe($first->json('data.0.id'))
        ->and(Document::withoutGlobalScopes()->count())->toBe(1);
    expect(Document::find($first->json('data.0.id'))->events()->where('step', 'ingest')->where('status', 'skipped')->exists())->toBeTrue();

    // The same file in another company is a new document there.
    $other = Company::factory()->create();
    $this->app['auth']->forgetGuards();
    Sanctum::actingAs(userWithRole('reviewer', $other));
    postFiles($this, [($this->pdf)('theirs.pdf')])->assertCreated()->assertJsonPath('meta.created', 1);
    expect(Document::withoutGlobalScopes()->count())->toBe(2);
});

it('lists documents with uploader, source filter and sender search', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);
    postFiles($this, [($this->pdf)()])->assertCreated();
    Document::factory()->create(['company_id' => $this->company->id, 'source' => 'email', 'email_from' => 'billing@kestrel-parts.example']);

    $this->getJson('/api/v1/documents?filter[source]=api')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.uploaded_by_name', $this->user->name);
    $this->getJson('/api/v1/documents?filter[q]=kestrel-parts')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.source', 'email');
    $this->getJson("/api/v1/documents?filter[uploaded_by]={$this->user->id}")->assertJsonCount(1, 'data');
});

it('gives every company an ap-{slug} inbox address', function () {
    expect(Company::factory()->create(['slug' => 'acme-gmbh'])->inbound_email)->toBe('ap-acme-gmbh@inbound.extractiq.test');
});
