<?php

use App\Enums\DocumentStatus;
use App\Jobs\PushToErp;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\ErpConnection;
use App\Models\ErpPush;
use App\Models\MockErp\Bill as MockBill;
use App\Services\Erp\ErpException;
use App\Services\Pipeline\EventRecorder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->doc = pushableInvoice($this->company, ['vendor_name' => 'Kestrel Industrial Parts LLC']);
    $this->mock = ErpConnection::for($this->company->id, 'mock');
    $this->mock->update(['is_active' => true]);
    $this->outage = fn (bool $on) => $this->mock->update(['settings' => ['outage' => $on]]);
    $this->attempt = function (int $n) {
        $job = new PushToErp($this->doc->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = $n;

        return $job;
    };
});

it('pushes a matched invoice to the active connector, keyed by the document uuid, and stores the ERP ref and link', function () {
    PushToErp::dispatchSync($this->doc->id);

    $doc = $this->doc->fresh();
    $push = ErpPush::withoutGlobalScopes()->sole();
    expect($doc->status)->toBe(DocumentStatus::Pushed)->and($doc->erp_ref)->toMatch('/^BILL-/')->and($doc->erp_url)->toContain('/mock-erp?bill=')
        ->and($doc->erp_connector)->toBe('mock')->and($doc->erp_status)->toBe('draft')
        ->and($push->idempotency_key)->toBe($doc->uuid)->and($push->status)->toBe('succeeded')->and($push->attempt)->toBe(1)
        ->and($push->http_status)->toBe(201)->and($push->request['number'])->toBe('INV-77')->and($push->response['ref'])->toBe($doc->erp_ref)
        ->and(MockBill::withoutGlobalScopes()->sole()->idempotency_key)->toBe($doc->uuid);
});

it('runs 5 attempts with backoff', function () {
    $job = new PushToErp($this->doc->id);
    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([30, 120, 600, 1800]);
});

it('only pushes approved + matched (or variance-accepted) invoices', function () {
    foreach ([DocumentStatus::Approved, DocumentStatus::MatchException, DocumentStatus::Validated] as $status) {
        $this->doc->forceFill(['status' => $status])->saveQuietly();
        PushToErp::dispatchSync($this->doc->id);
        expect($this->doc->fresh()->status)->toBe($status);
    }
    expect(ErpPush::withoutGlobalScopes()->count())->toBe(0);

    // Matched, but its match still has an open variance: not sent.
    $this->doc->forceFill(['status' => DocumentStatus::Matched])->saveQuietly();
    fakeMatch($this->doc, ['result' => 'exception']);
    PushToErp::dispatchSync($this->doc->id);
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Matched)
        ->and(DocumentEvent::withoutGlobalScopes()->where('document_id', $this->doc->id)->where('step', 'push')->latest('id')->first()->meta['reason'])->toBe('match_not_resolved');
});

it('logs every failed attempt and dead-letters after the last one with the ERP message', function () {
    ($this->outage)(true);
    foreach ([1, 2, 3, 4] as $n) {
        expect(fn () => ($this->attempt)($n)->handle(app(EventRecorder::class)))->toThrow(ErpException::class);
    }
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Matched)->and(ErpPush::withoutGlobalScopes()->where('status', 'failed')->count())->toBe(4);

    $last = ($this->attempt)(5);
    try {
        $last->handle(app(EventRecorder::class));
    } catch (ErpException $e) {
        $last->failed($e); // what the queue does after the final attempt
    }

    $doc = $this->doc->fresh();
    $dead = ErpPush::withoutGlobalScopes()->latest('id')->first();
    expect($doc->status)->toBe(DocumentStatus::PushFailed)->and($doc->last_error)->toContain('503 Service Unavailable')
        ->and($dead->status)->toBe('dead')->and($dead->attempt)->toBe(5)->and($dead->http_status)->toBe(503)->and($dead->retryable)->toBeTrue()
        ->and(ErpPush::withoutGlobalScopes()->pluck('attempt')->all())->toBe([1, 2, 3, 4, 5])
        ->and(DocumentEvent::withoutGlobalScopes()->where('document_id', $doc->id)->where('step', 'push')->where('to_status', 'push_failed')->first()->meta['dead_letter'])->toBeTrue();

    // Shown in the push log and the ERP Sync summary.
    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $this->getJson('/api/v1/erp-pushes?filter[status]=dead')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.error', 'Mock ERP: 503 Service Unavailable (simulated outage).')
        ->assertJsonPath('meta.summary.dead', 1);
});

it('fails fast on errors retrying cannot fix', function () {
    $this->doc->update(['total_minor' => 0]); // the ERP rejects it (422)
    PushToErp::dispatchSync($this->doc->id);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::PushFailed)
        ->and(ErpPush::withoutGlobalScopes()->sole())->status->toBe('dead')->http_status->toBe(422)->retryable->toBeFalse();
});

it('retries a dead-lettered push from the UI and never creates the bill twice', function () {
    ($this->outage)(true);
    $job = ($this->attempt)(5);
    try {
        $job->handle(app(EventRecorder::class));
    } catch (ErpException $e) {
        $job->failed($e);
    }
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::PushFailed);

    ($this->outage)(false);
    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $this->postJson("/api/v1/documents/{$this->doc->id}/retry")->assertStatus(202)->assertJsonPath('meta.queued_step', 'push');
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Pushed);

    // A second push of the same document reuses the successful one.
    $this->doc->fresh()->forceFill(['status' => DocumentStatus::PushFailed])->saveQuietly();
    PushToErp::dispatchSync($this->doc->id);
    expect(MockBill::withoutGlobalScopes()->count())->toBe(1)->and(ErpPush::withoutGlobalScopes()->where('status', 'succeeded')->count())->toBe(1);
});

it('waits while the invoice is on hold', function () {
    $this->doc->forceFill(['on_hold_at' => now(), 'hold_reason' => 'Checking bank details'])->saveQuietly();
    PushToErp::dispatchSync($this->doc->id);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Matched)->and(ErpPush::withoutGlobalScopes()->count())->toBe(0);
});

it('shows each attempt with its request and response', function () {
    PushToErp::dispatchSync($this->doc->id);
    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $id = ErpPush::withoutGlobalScopes()->sole()->id;

    $this->getJson('/api/v1/erp-pushes')->assertOk()->assertJsonMissingPath('data.0.attributes.request')
        ->assertJsonPath('data.0.attributes.document.number', 'INV-77')->assertJsonPath('meta.summary.succeeded', 1);
    $this->getJson("/api/v1/erp-pushes/$id")->assertOk()
        ->assertJsonPath('data.attributes.request.idempotencyKey', $this->doc->uuid)
        ->assertJsonPath('data.attributes.response.status', 'created');
});

it('gives every document a uuid and exposes the ERP link', function () {
    expect(Document::factory()->create(['company_id' => $this->company->id])->uuid)->toMatch('/^[0-9a-f-]{36}$/');
    PushToErp::dispatchSync($this->doc->id);
    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $this->getJson("/api/v1/documents/{$this->doc->id}")->assertJsonPath('data.attributes.erp_url', $this->doc->fresh()->erp_url)
        ->assertJsonPath('data.attributes.uuid', $this->doc->uuid);
});
