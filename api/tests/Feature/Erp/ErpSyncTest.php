<?php

use App\Enums\DocumentStatus;
use App\Jobs\PushToErp;
use App\Models\Document;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\GoodsReceipt;
use App\Models\MockErp\Bill as MockBill;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Erp\ErpSync;
use App\Services\Erp\MockErp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    app(MockErp::class)->seed($this->company->id, json_decode(file_get_contents(base_path('../samples/erp/seed.json')), true));
    ErpConnection::for($this->company->id, 'mock')->update(['is_active' => true]);
    $this->sync = fn () => app(ErpSync::class)->run($this->company->id, 'manual');
});

it('pulls vendors, purchase orders and receipts into master data, linking receipt lines by ERP line ref', function () {
    $run = ($this->sync)();

    expect($run->status)->toBe('succeeded')->and($run->counts['vendors'])->toBe(['created' => 4, 'updated' => 0])
        ->and($run->counts['purchase_orders'])->toBe(['created' => 26, 'updated' => 0])->and($run->counts['receipts'])->toBe(['created' => 4, 'updated' => 0]);
    $po = PurchaseOrder::with('lines', 'vendor')->where('po_number', 'PO-1008')->first();
    expect($po->source)->toBe('erp')->and($po->erp_ref)->toBe('mock:PO-1008')->and($po->vendor->erp_ref)->toBe('mock:V-100')
        ->and($po->lines[1]->erp_ref)->toBe('mock:PO-1008:2')
        ->and(GoodsReceipt::with('lines')->where('grn_number', 'GRN-5008')->first()->lines->pluck('po_line_id')->all())->toBe($po->lines->take(2)->pluck('id')->all());

    // Running it again updates, never duplicates.
    expect(($this->sync)()->counts['purchase_orders'])->toBe(['created' => 0, 'updated' => 26])->and(PurchaseOrder::count())->toBe(26);
});

it('re-matches invoices waiting on a receipt once the ERP records the delivery', function () {
    ($this->sync)();
    $vendor = Vendor::where('erp_ref', 'mock:V-100')->first();
    $inv = mdInvoice($this->company, [['KIP-4100', 100, 990, 'Safety gloves, cut level 5']], ['po_number' => 'PO-1008', 'vendor_id' => $vendor->id]);
    mdMatch($inv);
    expect($inv->fresh()->status)->toBe(DocumentStatus::MatchException)->and($inv->fresh()->latestMatch->lines[0]->result)->toBe('not_received');

    app(MockErp::class)->receiveRemaining(App\Models\MockErp\PurchaseOrder::withoutGlobalScopes()->where('number', 'PO-1008')->first());
    $run = ($this->sync)();

    expect($run->counts['rematched'])->toBe(1)->and($inv->fresh()->status)->toBeIn([DocumentStatus::Pushed, DocumentStatus::Matched]);
});

it('reads bill status back: pushed → posted → paid', function () {
    $doc = pushableInvoice($this->company);
    PushToErp::dispatchSync($doc->id);
    $bill = MockBill::withoutGlobalScopes()->where('ref', $doc->fresh()->erp_ref)->first();

    app(MockErp::class)->post($bill);
    expect(($this->sync)()->counts['bills'])->toMatchArray(['checked' => 1, 'posted' => 1])->and($doc->fresh()->status)->toBe(DocumentStatus::Posted);

    app(MockErp::class)->pay($bill->fresh());
    ($this->sync)();
    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Paid)->and($doc->paid_at)->not->toBeNull()->and($doc->erp_status)->toBe('paid')
        ->and($doc->events()->where('step', 'erp_status')->pluck('to_status')->all())->toBe(['posted', 'paid']);
});

it('records a failed run and flags the connection when the ERP is down', function () {
    ErpConnection::for($this->company->id, 'mock')->update(['settings' => ['outage' => true]]);
    $run = ($this->sync)();

    expect($run->status)->toBe('failed')->and($run->errors[0])->toContain('503')
        ->and(ErpConnection::for($this->company->id, 'mock')->status)->toBe('error');
});

it('is scheduled every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'extractiq:erp-sync'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');

    $this->artisan('extractiq:erp-sync --now')->assertSuccessful();
    expect(ErpSyncRun::count())->toBe(1);
});

describe('API', function () {
    beforeEach(fn () => Sanctum::actingAs($this->admin = userWithRole('admin', $this->company)));

    it('lists the connectors with state, capabilities and mapping; tests and activates them', function () {
        $this->getJson('/api/v1/erp/connections')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.active', 'mock')
            ->assertJsonPath('data.0.connector', 'mock')->assertJsonPath('data.0.capabilities.sync_receipts', true)
            ->assertJsonPath('data.2.connector', 'quickbooks')->assertJsonPath('data.2.status', 'not_configured');

        $this->postJson('/api/v1/erp/connections/mock/test')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.status', 'connected');

        config(['extractiq.erp.drivers.webhook' => ['url' => 'https://hooks.example/erp', 'secret' => 'x']]);
        Http::fake(['hooks.example/*' => Http::response([], 204)]);
        $this->postJson('/api/v1/erp/connections/webhook/activate')->assertOk()->assertJsonPath('meta.active', 'webhook');
        expect(ErpConnection::where('is_active', true)->pluck('connector')->all())->toBe(['webhook']);
        $this->postJson('/api/v1/erp/connections/sap/test')->assertNotFound();
    });

    it('saves the field mapping, GL account, tax codes and cost-centre rules, and validates them per ERP', function () {
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id]);
        $this->patchJson('/api/v1/erp/connections/odoo/mapping', [
            'fields' => ['document_number' => 'payment_reference'], 'default_account' => '610000',
            'tax_codes' => [['rate' => 8, 'code' => '8% Purchase']], 'cost_centres' => [['vendor_id' => $vendor->id, 'cost_centre' => 'Plant 1', 'account' => '620000']],
        ])->assertOk()->assertJsonPath('data.mapping.fields.document_number', 'payment_reference')
            ->assertJsonPath('data.mapping.fields.po_number', 'invoice_origin')->assertJsonPath('data.mapping.default_account', '610000');

        $this->patchJson('/api/v1/erp/connections/odoo/mapping', ['fields' => ['document_number' => 'name']])->assertStatus(422);
        $this->patchJson('/api/v1/erp/connections/odoo/mapping', ['tax_codes' => [['rate' => 8]]])->assertStatus(422);
        $other = Vendor::factory()->create(['company_id' => App\Models\Company::factory()->create()->id]);
        $this->patchJson('/api/v1/erp/connections/odoo/mapping', ['cost_centres' => [['vendor_id' => $other->id, 'cost_centre' => 'X']]])->assertStatus(422);
        // Webhook takes any field name.
        $this->patchJson('/api/v1/erp/connections/webhook/mapping', ['fields' => ['document_number' => 'invoice.ref']])->assertOk();

        Sanctum::actingAs(userWithRole('reviewer', $this->company));
        $this->patchJson('/api/v1/erp/connections/odoo/mapping', ['default_account' => '1'])->assertForbidden();
    });

    it('queues a sync now and lists past runs', function () {
        ($this->sync)();
        $this->postJson('/api/v1/erp/sync')->assertStatus(202)->assertJsonPath('meta.queued', true);
        $this->getJson('/api/v1/erp/sync-runs')->assertOk()->assertJsonPath('data.0.trigger', 'manual')->assertJsonPath('data.0.status', 'succeeded');
        $this->getJson('/api/v1/erp/connections')->assertJsonPath('meta.last_run.status', 'succeeded');
    });

    it('starts and finishes the QuickBooks connection from the browser', function () {
        config(['extractiq.erp.drivers.quickbooks.client_id' => 'cid', 'extractiq.erp.drivers.quickbooks.client_secret' => 'cs']);
        $url = $this->getJson('/api/v1/erp/quickbooks/connect')->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        Http::fake(['oauth.platform.intuit.com/*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600])]);
        $this->get("/api/v1/erp/quickbooks/callback?code=c&state={$q['state']}&realmId=9130")->assertRedirect('http://localhost:3030/erp-sync?qbo=connected');
        $this->get('/api/v1/erp/quickbooks/callback?error=access_denied')->assertRedirectContains('qbo=error');
        $this->getJson('/api/v1/erp/connections')->assertJsonPath('data.2.configured', true)->assertJsonPath('data.2.summary.realm_id', '9130');

        $this->postJson('/api/v1/erp/quickbooks/disconnect')->assertOk();
        expect(ErpConnection::for($this->company->id, 'quickbooks')->credentials)->toBeNull();
    });

    it('serves the Mock ERP screens: bills to post and pay, POs to receive, vendors, and the outage switch', function () {
        $doc = pushableInvoice($this->company);
        PushToErp::dispatchSync($doc->id);
        $bill = MockBill::withoutGlobalScopes()->first();

        $this->getJson('/api/v1/mock-erp/bills')->assertOk()->assertJsonPath('data.0.ref', $bill->ref)->assertJsonPath('meta.counts.draft', 1);
        $this->getJson("/api/v1/mock-erp/bills/{$bill->id}")->assertJsonPath('data.lines.0.account', '5000');
        $this->get("/api/v1/mock-erp/bills/{$bill->id}/attachment")->assertOk();
        $this->postJson("/api/v1/mock-erp/bills/{$bill->id}/post")->assertJsonPath('data.status', 'posted');
        $this->postJson("/api/v1/mock-erp/bills/{$bill->id}/post")->assertStatus(422);
        $this->postJson("/api/v1/mock-erp/bills/{$bill->id}/pay")->assertJsonPath('data.status', 'paid');

        $po = collect($this->getJson('/api/v1/mock-erp/purchase-orders')->assertOk()->json('data'))->firstWhere('number', 'PO-1008');
        expect($po['fully_received'])->toBeFalse()->and($po['lines'][1]['received'])->toEqual(6);
        $this->postJson("/api/v1/mock-erp/purchase-orders/{$po['id']}/receive")->assertCreated()->assertJsonPath('data.lines.0.quantity', 4);
        $this->getJson('/api/v1/mock-erp/vendors')->assertJsonCount(5, 'data'); // the 4 seeded + the bill's vendor, created on the fly

        $this->patchJson('/api/v1/erp/connections/mock/settings', ['outage' => true])->assertJsonPath('data.summary.outage', true);
        $this->postJson('/api/v1/erp/connections/mock/test')->assertJsonPath('data.ok', false)->assertJsonPath('data.status', 'error');
    });
});

describe('touchless processing', function () {
    it('approves a small validated invoice from a known vendor without review, and pushes it when it matches', function () {
        $this->company->update(['settings' => ['automation' => ['touchless' => true, 'touchless_max_total' => 1000]]]);
        ($this->sync)();
        foreach (['01-po-1001-kestrel.pdf', '02-grn-5001-kestrel.pdf'] as $f) {
            $d = ingestSample($this->company, $f);
            app(App\Services\Pipeline\DocumentWorkflow::class)->approve($d, null);
        }
        $inv = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf'); // $615.60

        expect($inv->fresh()->status)->toBe(DocumentStatus::Pushed)->and($inv->fresh()->touchless)->toBeTrue()->and($inv->fresh()->approved_by)->toBeNull()
            ->and($inv->events()->where('step', 'approve')->first()->meta)->toMatchArray(['auto' => true, 'touchless' => true]);
    });

    it('leaves invoices at or above the limit (or with the rule off) for a person', function () {
        $this->company->update(['settings' => ['automation' => ['touchless' => true, 'touchless_max_total' => 500]]]);
        ($this->sync)();
        $inv = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');
        expect($inv->fresh()->status)->toBe(DocumentStatus::Validated)->and($inv->fresh()->touchless)->toBeFalse();
    });

    it('is configured per company by admins', function () {
        Sanctum::actingAs(userWithRole('admin', $this->company));
        $this->getJson('/api/v1/settings/automation')->assertOk()->assertJsonPath('data.touchless', false);
        $this->patchJson('/api/v1/settings/automation', ['touchless' => true, 'touchless_max_total' => 2500])->assertOk()
            ->assertJsonPath('data.touchless', true)->assertJsonPath('data.touchless_max_total', 2500);
        $this->patchJson('/api/v1/settings/automation', ['touchless_max_total' => -1])->assertStatus(422);
        Sanctum::actingAs(userWithRole('reviewer', $this->company));
        $this->patchJson('/api/v1/settings/automation', ['touchless' => false])->assertForbidden();
        expect(Document::count())->toBe(0);
    });
});
