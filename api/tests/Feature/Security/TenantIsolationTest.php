<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\ErpConnection;
use App\Models\ErpPush;
use App\Models\MatchLine;
use App\Models\MockErp\Bill as MockBill;
use App\Models\MockErp\PurchaseOrder as MockPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorHint;
use App\Services\Erp\ErpSync;
use App\Services\Erp\MockErp;
use App\Services\Pipeline\DocumentWorkflow;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * Per-tenant data isolation, checked across every authenticated route: company B goes through
 * the real pipeline (ERP sync, extraction, approval, matching, push); then an admin of company A
 * calls every /api/v1 route with B's ids and must get 404s, and every list must come back empty.
 */
beforeEach(function () {
    $this->b = Company::factory()->create(['slug' => 'tenant-b']);
    $bAdmin = userWithRole('admin', $this->b);
    app(MockErp::class)->seed($this->b->id, json_decode((string) file_get_contents(base_path('../samples/erp/seed.json')), true));
    ErpConnection::for($this->b->id, 'mock')->update(['is_active' => true, 'status' => 'connected']);
    app(ErpSync::class)->run($this->b->id, 'manual');

    $invoice = ingestSample($this->b, '03-inv-k-2041-kestrel-3way-match.pdf');
    app(DocumentWorkflow::class)->approve($invoice, $bAdmin);
    VendorHint::withoutGlobalScopes()->create(['company_id' => $this->b->id, 'vendor_id' => Vendor::withoutGlobalScopes()->where('company_id', $this->b->id)->value('id'), 'field' => 'po_number', 'label' => 'Order Ref', 'hint' => 'x']);

    $first = fn (string $model) => $model::withoutGlobalScopes()->where('company_id', $this->b->id)->firstOrFail();
    $this->ids = [
        'document' => $invoice->id,
        'vendor' => $first(Vendor::class)->id,
        'purchaseOrder' => $first(PurchaseOrder::class)->id,
        'mockPurchaseOrder' => $first(MockPurchaseOrder::class)->id,
        'push' => $first(ErpPush::class)->id,
        'bill' => $first(MockBill::class)->id,
        'user' => $bAdmin->id,
        'hint' => $first(VendorHint::class)->id,
        'line' => MatchLine::withoutGlobalScopes()->where('document_match_id', DocumentMatch::withoutGlobalScopes()->where('invoice_id', $invoice->id)->value('id'))->firstOrFail()->id,
        'page' => 1, 'kind' => 'vendors', 'connector' => 'mock',
    ];
    expect(Document::withoutGlobalScopes()->find($invoice->id)->status->value)->toBeIn(['pushed', 'posted', 'paid']);

    $this->a = demoCompany();
    Sanctum::actingAs(User::factory()->role('admin')->create(['company_id' => $this->a->id]));
});

it('returns 404 for another tenant\'s records on every route', function () {
    $checked = 0;
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            continue;
        }
        preg_match_all('/\{(\w+)\??\}/', $uri, $m);
        $params = $m[1];
        // Only routes that point at a tenant's record (not just a connector name or an import kind).
        if (! array_diff($params, ['kind', 'connector', 'page'])) {
            continue;
        }
        $path = preg_replace_callback('/\{(\w+)\??\}/', function ($p) use ($uri) {
            $key = $p[1] === 'purchaseOrder' && str_contains($uri, 'mock-erp') ? 'mockPurchaseOrder' : $p[1];

            return (string) $this->ids[$key];
        }, $uri);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $res = $this->json($method, '/'.$path, ['reason' => 'tenant isolation check', 'po_number' => 'PO-X']);
            expect($res->status())->toBe(404, "$method /$path returned {$res->status()}: ".mb_substr($res->getContent(), 0, 200));
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(35); // every route that takes a tenant record id

    // And nothing changed on B's side.
    expect(Document::withoutGlobalScopes()->find($this->ids['document']))->not->toBeNull()
        ->and(VendorHint::withoutGlobalScopes()->find($this->ids['hint']))->not->toBeNull()
        ->and(User::find($this->ids['user'])->role->value)->toBe('admin');
});

it('never lists another tenant\'s data', function (string $path) {
    $res = $this->getJson($path)->assertOk();
    $data = $res->json('data');
    $items = is_array($data) && array_is_list($data) ? $data : ($res->json('data.items') ?? $res->json('items') ?? []);
    $ids = collect($items)->pluck('id')->map(fn ($v) => (string) $v);

    expect($ids->intersect(array_map('strval', [$this->ids['document'], $this->ids['vendor'], $this->ids['purchaseOrder'], $this->ids['push'], $this->ids['bill'], $this->ids['user']])))->toBeEmpty();
    expect(json_encode($res->json()))->not->toContain('INV-K-2041')->not->toContain('Kestrel');
})->with([
    '/api/v1/documents', '/api/v1/review-queue', '/api/v1/matches', '/api/v1/vendors', '/api/v1/purchase-orders', '/api/v1/goods-receipts',
    '/api/v1/erp-pushes', '/api/v1/erp/sync-runs', '/api/v1/mock-erp/bills', '/api/v1/mock-erp/purchase-orders', '/api/v1/mock-erp/vendors',
    '/api/v1/activity', '/api/v1/dashboard', '/api/v1/analytics',
]);

it('keeps the audit log export to the caller\'s tenant', function () {
    $csv = $this->get('/api/v1/audit-log/export')->assertOk()->streamedContent();

    expect(substr_count(trim($csv), "\n"))->toBe(0); // header only: company A has no events
});
