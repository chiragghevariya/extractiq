<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\Vendor;
use App\Models\VendorHint;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->reviewer = userWithRole('reviewer', $this->company);
    $this->other = userWithRole('reviewer', $this->company);
    Sanctum::actingAs($this->reviewer);
    $this->doc = fn (array $attrs = []) => Document::factory()->status(DocumentStatus::NeedsReview)->withLines([['A-1', 2, 5000]])
        ->create($attrs + ['company_id' => $this->company->id, 'po_number' => 'PO-1', 'subtotal_minor' => 10000, 'tax_minor' => 800, 'total_minor' => 10800]);
});

it('PATCH /extraction applies corrections and records a field-level diff with who and when', function () {
    $doc = ($this->doc)(['document_number' => 'INV-1O1', 'total_minor' => 99999]);

    $this->patchJson("/api/v1/documents/{$doc->id}/extraction", [
        'document_number' => 'INV-101',
        'total_minor' => 10800,
        'lines' => [
            ['sku' => 'A-1', 'description' => 'Item A-1', 'quantity' => 2, 'unit_price_minor' => 5000, 'amount_minor' => 10000],
            ['sku' => 'B-2', 'description' => 'Freight', 'quantity' => 1, 'unit_price_minor' => 0, 'amount_minor' => 0],
        ],
    ])->assertOk()->assertJsonPath('data.attributes.document_number', 'INV-101');

    $event = $doc->events()->where('step', 'review')->sole();
    expect($event->user_id)->toBe($this->reviewer->id)
        ->and($event->created_at)->not->toBeNull()
        ->and($event->meta['diff']['fields'])->toBe([
            'document_number' => ['before' => 'INV-1O1', 'after' => 'INV-101'],
            'total_minor' => ['before' => 99999, 'after' => 10800],
        ])
        ->and($event->meta['diff']['lines'])->toBe([['line' => 2, 'change' => 'added', 'after' => [
            'sku' => 'B-2', 'description' => 'Freight', 'quantity' => 1, 'uom' => null, 'unit_price_minor' => 0, 'tax_rate' => null, 'amount_minor' => 0,
        ]]]);
});

it('records changed and removed lines', function () {
    $doc = Document::factory()->status(DocumentStatus::NeedsReview)->withLines([['A-1', 2, 5000], ['B-2', 1, 300]])->create(['company_id' => $this->company->id]);

    $this->patchJson("/api/v1/documents/{$doc->id}/extraction", ['lines' => [
        ['sku' => 'A-1', 'description' => 'Item A-1', 'quantity' => 3, 'unit_price_minor' => 5000, 'amount_minor' => 15000],
    ]])->assertOk();

    $lines = $doc->events()->where('step', 'review')->sole()->meta['diff']['lines'];
    expect($lines[0])->toMatchArray(['line' => 1, 'change' => 'changed'])
        ->and($lines[0]['fields']['quantity'])->toBe(['before' => 2, 'after' => 3])
        ->and($lines[0]['fields']['amount_minor'])->toBe(['before' => 10000, 'after' => 15000])
        ->and($lines[1])->toMatchArray(['line' => 2, 'change' => 'removed']);
});

it('accepting low-confidence fields confirms them; untouched ones keep the document in review', function () {
    $doc = ($this->doc)(['extraction' => ['schema' => 'invoice.v1', 'fields' => [
        'invoice_date' => ['confidence' => 0.74, 'has_value' => true],
        'total' => ['confidence' => 0.8, 'has_value' => true],
        'invoice_number' => ['confidence' => 0.99, 'has_value' => true],
    ]]]);

    $this->patchJson("/api/v1/documents/{$doc->id}/extraction", ['accepted' => ['document_date']])->assertOk()
        ->assertJsonPath('data.attributes.status', 'needs_review')      // total still 0.80
        ->assertJsonPath('data.attributes.field_confidence.document_date', 1);

    $this->patchJson("/api/v1/documents/{$doc->id}/extraction", ['accepted' => ['total']])->assertOk()
        ->assertJsonPath('data.attributes.status', 'validated');

    $diffs = $doc->events()->where('step', 'review')->orderBy('id')->get()->pluck('meta.diff.accepted')->all();
    expect($diffs)->toBe([['document_date'], ['total']])
        ->and($doc->fresh()->extraction['fields']['invoice_date']['human_verified'])->toBeTrue();
});

it('corrections through /extraction feed vendor memory', function () {
    Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Brightline Office Supply Co.']);
    $doc = ingestSample($this->company, '16-inv-bl-7801-order-ref.pdf');

    $this->patchJson("/api/v1/documents/{$doc->id}/extraction", ['po_number' => 'PO-1005'])->assertOk();

    expect(VendorHint::withoutGlobalScopes()->sole()->label)->toBe('Order Ref')
        ->and($doc->events()->where('step', 'review')->sole()->meta['hints_learned'])->toHaveCount(1);
});

it('approves with pending edits in one call (Ctrl+Enter) and logs both events', function () {
    $doc = ($this->doc)(['po_number' => null]);

    $this->postJson("/api/v1/documents/{$doc->id}/approve", ['po_number' => 'PO-77'])->assertOk()
        ->assertJsonPath('data.attributes.po_number', 'PO-77');

    // Review (the pending edit) is logged, then the approval; matching/push follow on the sync test queue.
    $steps = $doc->events()->whereIn('step', ['review', 'approve'])->orderBy('id')->pluck('step')->all();
    expect($steps)->toBe(['review', 'approve'])
        ->and($doc->events()->where('step', 'approve')->sole()->meta['diff']['fields']['status'])->toBe(['before' => 'validated', 'after' => 'approved']);
});

it('refuses to approve while a check fails', function () {
    $doc = ($this->doc)(['total_minor' => 99999]);

    $this->postJson("/api/v1/documents/{$doc->id}/approve", ['accepted' => ['total']])->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/validation');
});

it('rejects with a reason, and can reopen', function () {
    $doc = ($this->doc)();

    $this->postJson("/api/v1/documents/{$doc->id}/reject", ['reason' => 'x'])->assertStatus(422);
    $this->postJson("/api/v1/documents/{$doc->id}/reject", ['reason' => 'Not addressed to us'])->assertOk()
        ->assertJsonPath('data.attributes.status', 'rejected')
        ->assertJsonPath('data.attributes.rejection_reason', 'Not addressed to us')
        ->assertJsonPath('data.attributes.editable', false);
    expect($doc->events()->where('step', 'reject')->sole())->toMatchArray(['user_id' => $this->reviewer->id])
        ->and($doc->events()->where('step', 'reject')->sole()->meta['reason'])->toBe('Not addressed to us');

    $this->postJson("/api/v1/documents/{$doc->id}/reopen")->assertOk()->assertJsonPath('data.attributes.status', 'needs_review');
});

it('reassigns within the company and logs before/after', function () {
    $doc = ($this->doc)();

    $this->postJson("/api/v1/documents/{$doc->id}/reassign", ['assignee_id' => $this->other->id])->assertOk()
        ->assertJsonPath('data.attributes.assignee.name', $this->other->name);
    $this->postJson("/api/v1/documents/{$doc->id}/reassign", ['assignee_id' => null])->assertOk()
        ->assertJsonPath('data.attributes.assignee', null);

    $diff = $doc->events()->where('step', 'reassign')->orderBy('id')->first()->meta['diff']['fields']['assignee'];
    expect($diff)->toBe(['before' => null, 'after' => ['id' => (string) $this->other->id, 'name' => $this->other->name]]);

    $outsider = userWithRole('reviewer', Company::factory()->create());
    $this->postJson("/api/v1/documents/{$doc->id}/reassign", ['assignee_id' => $outsider->id])->assertStatus(422);
});

it('lists the review queue by due date with counts, SLA and the J/K order', function () {
    config(['extractiq.review.sla_hours' => 24, 'extractiq.review.sla_warning_hours' => 4]);
    $late = ($this->doc)(['due_date' => '2026-12-20', 'received_at' => now()->subHours(30)]);
    $soon = ($this->doc)(['due_date' => '2026-12-01', 'received_at' => now()->subHours(21), 'assignee_id' => $this->reviewer->id]);
    $none = ($this->doc)(['due_date' => null, 'received_at' => now()->subHour()]);
    ($this->doc)(['status' => DocumentStatus::Pushed]);

    $res = $this->getJson('/api/v1/review-queue')->assertOk()
        ->assertJsonPath('meta.counts', ['total' => 3, 'mine' => 1, 'unassigned' => 2, 'overdue' => 1, 'due_soon' => 1])
        ->assertJsonPath('meta.sla.hours', 24);
    expect(collect($res->json('data'))->pluck('id')->all())->toBe([(string) $soon->id, (string) $late->id, (string) $none->id])
        ->and($res->json('meta.order'))->toBe([(string) $soon->id, (string) $late->id, (string) $none->id])
        ->and($res->json('data.0.attributes.assignee.name'))->toBe($this->reviewer->name)
        ->and($res->json('data.0.attributes.sla_due_at'))->not->toBeNull();

    $this->getJson('/api/v1/review-queue?filter[assignee]=me')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/review-queue?filter[assignee]=unassigned')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/review-queue?filter[sla]=overdue')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $late->id);
});

it('lists workspace users for assignment', function () {
    $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.attributes.can_review', true);
});
