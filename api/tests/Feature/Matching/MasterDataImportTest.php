<?php

use App\Enums\DocumentStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Matching\CsvImporter;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->import = fn (string $kind, string $csv) => app(CsvImporter::class)->import($this->company->id, $kind, $csv);
    $this->vendorsCsv = "name,tax_id,aliases,email,default_currency,erp_ref\n"
        ."Kestrel Industrial Parts LLC,88-1234567,Kestrel Parts;KIP,billing@kestrel.example,USD,V-100\n"
        ."Harbor & Pine Packaging Ltd,DE123456789,Harbor and Pine,,EUR,V-200\n";
    $this->posCsv = "po_number,vendor,order_date,currency,line_no,sku,description,quantity,uom,unit_price\n"
        ."PO-7001,V-100,2026-11-01,,1,A-1,Hex bolt M8,500,ea,0.42\n"
        ."PO-7001,V-100,2026-11-01,,2,B-2,Flange nut M8,500,ea,0.18\n"
        ."PO-7002,Harbor and Pine,2026-11-02,EUR,1,BOX-L,Shipping box large,\"1,000\",ea,\"1,25\"\n";
});

it('imports vendors with aliases, and re-importing updates instead of duplicating', function () {
    $r = ($this->import)('vendors', $this->vendorsCsv);
    expect($r)->toMatchArray(['created' => 2, 'updated' => 0, 'errors' => []]);

    $k = Vendor::where('erp_ref', 'V-100')->first();
    expect($k->aliases)->toBe(['Kestrel Parts', 'KIP'])->and($k->alias_keys)->toBe(['kestrel parts', 'kip'])->and($k->tax_key)->toBe('881234567')->and($k->source)->toBe('csv');

    $r2 = ($this->import)('vendors', "name,tax_id,aliases\nKestrel Industrial Parts LLC,,Kestrel Ind.\n,,\n");
    expect($r2)->toMatchArray(['created' => 0, 'updated' => 1])->and(Vendor::count())->toBe(2)
        ->and($k->fresh()->aliases)->toBe(['Kestrel Parts', 'KIP', 'Kestrel Ind.']);
});

it('imports purchase orders one row per line, finding the vendor by ERP ref, alias or tax ID', function () {
    ($this->import)('vendors', $this->vendorsCsv);
    $r = ($this->import)('purchase_orders', $this->posCsv);

    expect($r)->toMatchArray(['created' => 2, 'errors' => []]);
    $po = PurchaseOrder::with('lines', 'vendor')->where('po_number', 'PO-7001')->first();
    expect($po->vendor->name)->toBe('Kestrel Industrial Parts LLC')->and($po->currency)->toBe('USD')->and($po->source)->toBe('csv')
        ->and($po->lines)->toHaveCount(2)->and($po->lines[0]->unit_price_minor)->toBe(42)->and($po->subtotal_minor)->toBe(30000);
    expect(PurchaseOrder::where('po_number', 'PO-7002')->first()->lines[0]->quantity)->toBe(1000.0);
});

it('reports bad rows with row numbers and still imports the good records', function () {
    ($this->import)('vendors', $this->vendorsCsv);
    $r = ($this->import)('purchase_orders', "po_number,vendor,sku,description,quantity,unit_price\n"
        ."PO-1,V-100,A-1,Bolt,10,1.00\n"
        ."PO-2,Nobody Inc,A-1,Bolt,10,1.00\n"
        ."PO-3,V-100,A-1,Bolt,ten,1.00\n"
        .",V-100,A-1,Bolt,1,1.00\n");

    expect($r['created'])->toBe(1)->and(collect($r['errors'])->pluck('row')->all())->toBe([3, 4, 5])
        ->and($r['errors'][0]['message'])->toContain('Nobody Inc')->and($r['errors'][1]['message'])->toContain('quantity');
    expect(($this->import)('vendors', "vendor_name\nX\n")['errors'][0]['message'])->toContain('Missing the "name" column');
});

it('imports goods receipts and links each line to its PO line (by po_line_no or SKU)', function () {
    ($this->import)('vendors', $this->vendorsCsv);
    ($this->import)('purchase_orders', $this->posCsv);
    $r = ($this->import)('goods_receipts', "grn_number,po_number,received_date,line_no,po_line_no,sku,description,quantity\n"
        ."GRN-1,PO-7001,2026-11-05,1,2,,Nuts,300\n"
        ."GRN-1,PO-7001,2026-11-05,2,,A-1,Bolts,500\n"
        ."GRN-2,PO-9999,2026-11-05,1,,A-1,Bolts,1\n");

    expect($r['created'])->toBe(1)->and($r['errors'][0]['message'])->toContain('PO-9999');
    $grn = GoodsReceipt::with('lines.poLine')->first();
    expect($grn->purchaseOrder->po_number)->toBe('PO-7001')
        ->and($grn->lines->map(fn ($l) => $l->poLine->sku)->all())->toBe(['B-2', 'A-1']);
});

it('keeps goods-receipt links when a PO is re-imported', function () {
    ($this->import)('vendors', $this->vendorsCsv);
    ($this->import)('purchase_orders', $this->posCsv);
    ($this->import)('goods_receipts', "grn_number,po_number,sku,description,quantity\nGRN-1,PO-7001,A-1,Bolts,500\n");
    $lineId = GoodsReceipt::first()->lines[0]->po_line_id;

    ($this->import)('purchase_orders', str_replace('0.42', '0.45', $this->posCsv));
    expect(GoodsReceipt::first()->lines[0]->po_line_id)->toBe($lineId)
        ->and(PurchaseOrder::where('po_number', 'PO-7001')->first()->lines[0]->unit_price_minor)->toBe(45);
});

it('imports over HTTP (admin only), re-matches waiting invoices, and serves templates', function () {
    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $file = fn (string $csv) => UploadedFile::fake()->createWithContent('data.csv', $csv);
    $this->postJson('/api/v1/imports/vendors', ['file' => $file($this->vendorsCsv)])->assertForbidden();

    Sanctum::actingAs(userWithRole('admin', $this->company));
    $this->postJson('/api/v1/imports/vendors', ['file' => $file($this->vendorsCsv)])->assertOk()->assertJsonPath('meta.created', 2);

    // An invoice stuck because its PO wasn't known yet…
    $inv = mdInvoice($this->company, [['A-1', 500, 42], ['B-2', 500, 18]], ['po_number' => 'PO-7001', 'vendor_id' => Vendor::where('erp_ref', 'V-100')->value('id')]);
    mdMatch($inv);
    expect($inv->fresh()->status)->toBe(DocumentStatus::MatchException);

    // …matches once the PO arrives.
    $this->postJson('/api/v1/imports/purchase_orders', ['file' => $file($this->posCsv)])->assertOk()->assertJsonPath('meta.rematched', 1);
    expect($inv->fresh()->status)->toBe(DocumentStatus::Pushed);

    $this->postJson('/api/v1/imports/purchase_orders', ['file' => $file("po_number,vendor\nPO-1,Nobody\n")])->assertOk()
        ->assertJsonPath('meta.created', 0)->assertJsonPath('meta.errors.0.row', 2);
    $this->postJson('/api/v1/imports/purchase_orders', [])->assertStatus(422);
    $this->get('/api/v1/imports/goods_receipts/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertSee('grn_number,po_number,received_date');
    $this->postJson('/api/v1/imports/invoices', ['file' => $file('x')])->assertNotFound();
});

it('keeps each company’s master data separate', function () {
    ($this->import)('vendors', $this->vendorsCsv);
    $other = App\Models\Company::factory()->create();
    $r = app(CsvImporter::class)->import($other->id, 'purchase_orders', $this->posCsv);

    expect($r['created'])->toBe(0)->and($r['errors'])->toHaveCount(2); // vendors exist only in the demo company
});
