<?php

use App\Enums\DocumentStatus;
use App\Jobs\PushToErp;
use App\Models\Company;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->admin = userWithRole('admin', $this->company);
    $this->reviewer = userWithRole('reviewer', $this->company);
    $v = mdVendor($this->company, 'Kestrel Industrial Parts LLC');
    mdPo($this->company, 'PO-9', [['A-1', 10, 1000], ['B-2', 5, 2000]], ['vendor_id' => $v->id]);
    // Line 1 +10% price, line 2 not on the PO.
    $this->inv = mdInvoice($this->company, [['A-1', 10, 1100], ['FRT', 1, 2500, 'Freight']], ['vendor_id' => $v->id]);
    $this->match = mdMatch($this->inv);
    $this->url = "/api/v1/documents/{$this->inv->id}";
});

it('shows the match with each line beside its PO line and the deltas', function () {
    Sanctum::actingAs($this->reviewer);
    $res = $this->getJson("{$this->url}/match")->assertOk()
        ->assertJsonPath('data.attributes.result', 'exception')
        ->assertJsonPath('data.attributes.purchase_order.number', 'PO-9')
        ->assertJsonPath('data.attributes.lines.0.result', 'price_variance')
        ->assertJsonPath('data.attributes.lines.0.po.unit_price_minor', 1000)
        ->assertJsonPath('data.attributes.lines.0.deltas.price_minor', 100)
        ->assertJsonPath('data.attributes.lines.0.deltas.price_pct', 10)
        ->assertJsonPath('data.attributes.lines.1.result', 'not_on_po')
        ->assertJsonPath('meta.can_accept', false);
    expect($res->json('data.attributes.lines.0.message'))->toContain('unit price');
});

it('accepting a variance needs a reason and the admin role (by default)', function () {
    $line = $this->match->lines[0];
    Sanctum::actingAs($this->reviewer);
    $this->postJson("{$this->url}/match/lines/{$line->id}/accept", ['reason' => 'Agreed price increase'])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->url}/match/lines/{$line->id}/accept", ['reason' => 'ok'])->assertStatus(422);
    $this->postJson("{$this->url}/match/lines/{$line->id}/accept", ['reason' => 'Agreed price increase'])->assertOk()
        ->assertJsonPath('data.attributes.lines.0.ok', true)
        ->assertJsonPath('data.attributes.result', 'exception'); // freight line still open

    expect($this->inv->fresh()->status)->toBe(DocumentStatus::MatchException)
        ->and($this->inv->events()->where('step', 'match_accept')->first()->meta)->toMatchArray(['line_no' => 1, 'result' => 'price_variance', 'reason' => 'Agreed price increase']);

    // Accepting the last open line matches the invoice and sends it to the ERP.
    $this->postJson("{$this->url}/match/lines/{$this->match->lines[1]->id}/accept", ['reason' => 'Freight agreed on the phone'])->assertOk()
        ->assertJsonPath('data.attributes.result', 'accepted');
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::Pushed);
});

it('lets reviewers accept when the company allows it', function () {
    $this->company->update(['settings' => ['matching' => ['accept_role' => 'reviewer']]]);
    Sanctum::actingAs($this->reviewer);
    $this->postJson("{$this->url}/match/accept", ['reason' => 'Both agreed with the vendor'])->assertOk()->assertJsonPath('data.attributes.result', 'accepted');
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::Pushed);
});

it('requests a credit note for the disputed amount and keeps the invoice in exception', function () {
    Sanctum::actingAs($this->reviewer);
    $this->postJson("{$this->url}/match/credit-note", ['reason' => 'Bill us at PO price and drop the freight'])->assertOk()
        ->assertJsonPath('data.attributes.credit_note.amount_minor', 3500) // 10 × $1.00 + $25.00 freight
        ->assertJsonPath('data.attributes.credit_note.by', $this->reviewer->name);
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::MatchException)
        ->and($this->inv->events()->where('step', 'credit_note')->first()->meta['amount_minor'])->toBe(3500);

    $this->getJson('/api/v1/matches?filter[result]=credit_note')->assertJsonCount(1, 'data');
});

it('puts an invoice on hold so it is not pushed, and pushes it on release', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->url}/hold", ['reason' => 'Vendor bank details changed — verifying'])->assertOk()
        ->assertJsonPath('data.attributes.on_hold', true);
    $this->postJson("{$this->url}/hold", ['reason' => 'again'])->assertStatus(422);

    $this->postJson("{$this->url}/match/accept", ['reason' => 'Accepted for payment'])->assertOk();
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::Matched); // accepted, but held back from the ERP
    PushToErp::dispatchSync($this->inv->id);
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::Matched)
        ->and($this->inv->events()->where('step', 'push')->latest('id')->first()->meta['reason'])->toBe('on_hold');
    $this->getJson('/api/v1/matches?filter[result]=on_hold')->assertJsonCount(1, 'data');

    $this->postJson("{$this->url}/release", ['note' => 'Verified by phone'])->assertOk()->assertJsonPath('data.attributes.on_hold', false);
    expect($this->inv->fresh()->status)->toBe(DocumentStatus::Pushed);
});

it('refuses match actions on invoices that are not in exception', function () {
    $ok = mdInvoice($this->company, [['A-1', 10, 1000], ['B-2', 5, 2000]], ['document_number' => 'INV-OK']);
    mdMatch($ok);
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/documents/{$ok->id}/match/accept", ['reason' => 'Nothing to accept'])->assertStatus(422);
    $this->postJson("/api/v1/documents/{$ok->id}/match/credit-note", ['reason' => 'Nothing to credit'])->assertStatus(422);
});

it('re-runs matching on request', function () {
    Sanctum::actingAs($this->reviewer);
    $this->postJson("{$this->url}/match/rematch")->assertStatus(202);
    expect($this->inv->matches()->count())->toBe(2)->and($this->match->fresh()->is_current)->toBeFalse();
});

it('reads and updates the matching tolerances (admin only)', function () {
    Sanctum::actingAs($this->reviewer);
    $this->getJson('/api/v1/settings/matching')->assertOk()->assertJsonPath('data.price_tolerance_pct', 2)->assertJsonPath('data.accept_role', 'admin');
    $this->patchJson('/api/v1/settings/matching', ['price_tolerance_pct' => 5])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->patchJson('/api/v1/settings/matching', ['price_tolerance_pct' => 12.5, 'total_tolerance_abs' => 30, 'mode' => 'three_way'])->assertOk()
        ->assertJsonPath('data.price_tolerance_pct', 12.5)->assertJsonPath('data.mode', 'three_way')->assertJsonPath('data.qty_tolerance_pct', 0);
    $errors = $this->patchJson('/api/v1/settings/matching', ['mode' => 'four_way', 'price_tolerance_pct' => -1])->assertStatus(422)->json('errors');
    expect(collect($errors)->pluck('source.pointer')->sort()->values()->all())->toBe(['/mode', '/price_tolerance_pct']);
    expect(Company::find($this->company->id)->settings['matching']['price_tolerance_pct'])->toEqual(12.5);
});
