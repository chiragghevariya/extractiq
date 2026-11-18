<?php

use App\Enums\DocumentStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Laravel\Sanctum\Sanctum;

/** Ingest a sample and approve it the way a reviewer would (the demo seeder does the same). */
function ingestApproved(App\Models\Company $c, string $file): App\Models\Document
{
    $doc = ingestSample($c, $file);
    if (in_array($doc->status, [DocumentStatus::Validated, DocumentStatus::NeedsReview], true)) {
        app(App\Services\Pipeline\DocumentWorkflow::class)->approve($doc, null);
    }

    return $doc->fresh();
}

beforeEach(function () {
    $this->company = demoCompany();
    mdVendor($this->company, 'Kestrel Industrial Parts LLC', ['tax_id' => '88-1234567']);
    mdVendor($this->company, 'Harbor & Pine Packaging Ltd', ['tax_id' => 'DE123456789']);
});

it('turns approved PO and GRN documents into master data, and matches the invoice 3-way', function () {
    ingestApproved($this->company, '01-po-1001-kestrel.pdf');
    ingestApproved($this->company, '02-grn-5001-kestrel.pdf');
    $inv = ingestApproved($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');

    $po = PurchaseOrder::with('lines')->where('po_number', 'PO-1001')->first();
    expect($po->source)->toBe('document')->and($po->source_document_id)->not->toBeNull()->and($po->lines)->toHaveCount(3)
        ->and($po->vendor->name)->toBe('Kestrel Industrial Parts LLC');
    $grn = GoodsReceipt::with('lines')->where('grn_number', 'GRN-5001')->first();
    expect($grn->purchase_order_id)->toBe($po->id)->and($grn->lines->whereNull('po_line_id'))->toHaveCount(0);

    $m = $inv->fresh()->latestMatch;
    expect($inv->fresh()->status)->toBe(DocumentStatus::Pushed)->and($m->kind)->toBe('three_way')->and($m->lines->pluck('result')->unique()->all())->toBe(['matched']);
});

it('flags the partial-delivery scenario line by line', function () {
    foreach (['06-po-1003-harbor-eur.pdf', '07-grn-5003-harbor-partial.pdf', '08-inv-hp-3310-harbor-qty-exception.pdf'] as $f) {
        $inv = ingestApproved($this->company, $f);
    }
    $m = $inv->fresh()->latestMatch;
    expect($inv->fresh()->status)->toBe(DocumentStatus::MatchException)
        ->and($m->lines->pluck('result')->all())->toBe(['qty_variance', 'matched'])
        ->and($m->lines[0]->received_qty)->toBe(800.0)->and($m->lines[0]->invoiced_qty)->toBe(1000.0);
});

it('never lets an extracted PO overwrite one from the ERP or a CSV', function () {
    mdPo($this->company, 'PO-1001', [['KIP-HB-M8', 999, 1]], [], 'erp');
    ingestApproved($this->company, '01-po-1001-kestrel.pdf');

    $po = PurchaseOrder::with('lines')->where('po_number', 'PO-1001')->first();
    expect($po->source)->toBe('erp')->and($po->lines)->toHaveCount(1)->and($po->lines[0]->quantity)->toBe(999.0)
        ->and($po->source_document_id)->not->toBeNull();
});

it('lists purchase orders with received and billed progress, and shows one in detail', function () {
    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $v = mdVendor($this->company, 'Brightline Office Supply Co.');
    mdPo($this->company, 'PO-9', [['A-1', 10, 1000], ['B-2', 10, 500]], ['vendor_id' => $v->id]);
    mdPo($this->company, 'PO-10', [['A-1', 1, 1000]], ['vendor_id' => $v->id, 'status' => 'closed']);
    mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 10]]);
    mdMatch(mdInvoice($this->company, [['A-1', 10, 1000]], ['vendor_id' => $v->id]));

    $res = collect($this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonPath('meta.counts.closed', 1)->json('data'))->firstWhere('attributes.number', 'PO-9');
    $row = $res['attributes'] + ['id' => $res['id']];
    expect($row)->toMatchArray(['received_pct' => 0.5, 'billed_pct' => 0.5, 'billed_minor' => 10000, 'receipt_count' => 1, 'source' => 'csv'])
        ->and($row['vendor']['name'])->toBe('Brightline Office Supply Co.');
    $this->getJson('/api/v1/purchase-orders?filter[status]=closed')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/purchase-orders?filter[q]=brightline')->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/purchase-orders/{$row['id']}")->assertOk()
        ->assertJsonPath('data.attributes.lines.0.received_qty', 10)->assertJsonPath('data.attributes.lines.0.billed_qty', 10)
        ->assertJsonPath('data.attributes.lines.1.received_qty', 0)->assertJsonPath('data.attributes.receipts.0.number', 'GRN-1')
        ->assertJsonPath('data.attributes.invoices.0.match_result', 'matched');
    $this->getJson('/api/v1/goods-receipts')->assertOk()->assertJsonPath('data.0.attributes.purchase_order.number', 'PO-9')->assertJsonPath('data.0.attributes.unlinked_lines', 0);

    $other = mdPo(App\Models\Company::factory()->create(), 'PO-X', [['A', 1, 1]]);
    $this->getJson("/api/v1/purchase-orders/{$other->id}")->assertNotFound();
});
