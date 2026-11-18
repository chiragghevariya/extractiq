<?php

use App\Enums\DocumentStatus;
use App\Models\Company;

beforeEach(function () {
    $this->company = demoCompany();
    $this->vendor = mdVendor($this->company, 'Kestrel Industrial Parts LLC', ['tax_id' => '88-1234567']);
    // PO-9: A-1 10 × $10.00, B-2 5 × $20.00 = $200.00
    $this->po = fn (array $header = []) => mdPo($this->company, 'PO-9', [['A-1', 10, 1000], ['B-2', 5, 2000]], $header + ['vendor_id' => $this->vendor->id]);
    $this->invoice = fn (array $lines = [['A-1', 10, 1000], ['B-2', 5, 2000]], array $attrs = []) => mdInvoice($this->company, $lines, $attrs + ['vendor_id' => $this->vendor->id]);
    $this->codes = fn ($m) => collect($m->header_issues)->pluck('code')->all();
});

describe('2-way and 3-way', function () {
    it('matches an invoice to its PO line by line (2-way)', function () {
        ($this->po)();
        $m = mdMatch(($this->invoice)());

        expect($m->kind)->toBe('two_way')->and($m->result)->toBe('matched')->and($m->po_method)->toBe('po_number')
            ->and(mdResults($m))->toBe(['matched', 'matched'])
            ->and($m->lines->pluck('method')->unique()->all())->toBe(['sku'])
            ->and($m->totals['delta_minor'])->toBe(0);
    });

    it('finds the PO whatever the number formatting', function () {
        ($this->po)();
        expect(mdMatch(($this->invoice)(attrs: ['po_number' => 'po 9']))->po_method)->toBe('po_number');
    });

    it('becomes 3-way once goods are received, and matches what was received', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 10], ['B-2', 5]]);
        $m = mdMatch(($this->invoice)());

        expect($m->kind)->toBe('three_way')->and($m->result)->toBe('matched')->and($m->goods_receipt_ids)->toHaveCount(1)
            ->and($m->lines->pluck('received_qty')->all())->toBe([10.0, 5.0]);
    });
});

describe('variance types', function () {
    it('price_variance: beyond the % tolerance, with the delta and the money at stake', function () {
        ($this->po)();
        $m = mdMatch(($this->invoice)([['A-1', 10, 1100], ['B-2', 5, 2000]])); // +10%

        $line = $m->lines->firstWhere('line_no', 1);
        expect(mdResults($m))->toBe(['price_variance', 'matched'])->and($m->result)->toBe('exception')
            ->and($line->invoice_price_minor - $line->po_price_minor)->toBe(100)
            ->and($line->disputed_minor)->toBe(1000); // 10 × $1.00
    });

    it('price_variance: small drift passes the % tolerance, small cents pass the absolute one', function () {
        ($this->po)();
        expect(mdMatch(($this->invoice)([['A-1', 10, 1015], ['B-2', 5, 2000]]))->result)->toBe('matched'); // +1.5% < 2%

        mdPo($this->company, 'PO-CHEAP', [['W-1', 10, 100]], ['vendor_id' => $this->vendor->id]); // $1.00 washers
        $m = mdMatch(mdInvoice($this->company, [['W-1', 10, 104]], ['po_number' => 'PO-CHEAP', 'vendor_id' => $this->vendor->id])); // +4¢ (4%) ≤ $0.05
        expect($m->result)->toBe('matched');
    });

    it('qty_variance: invoiced more than was ordered (2-way)', function () {
        ($this->po)();
        $m = mdMatch(($this->invoice)([['A-1', 12, 1000], ['B-2', 5, 2000]]));

        expect(mdResults($m))->toBe(['qty_variance', 'matched'])->and($m->lines->first()->disputed_minor)->toBe(2000);
    });

    it('not_received: a PO line with no receipt on a 3-way match', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 10]]);
        $m = mdMatch(($this->invoice)());

        expect(mdResults($m))->toBe(['matched', 'not_received'])->and($m->lines[1]->disputed_minor)->toBe(10000);
    });

    it('not_on_po: an invoice line the PO does not have', function () {
        ($this->po)();
        $m = mdMatch(($this->invoice)([['A-1', 10, 1000], ['FRT', 1, 2500, 'Freight surcharge']]));

        expect(mdResults($m))->toBe(['matched', 'not_on_po'])->and($m->lines[1]->po_line_id)->toBeNull()
            ->and($m->totals['disputed_minor'])->toBe(2500)
            ->and($m->totals['delta_minor'])->toBe(2500)   // shown as the difference from PO prices…
            ->and($m->header_issues)->toBe([]);            // …but not reported twice as a total variance
    });

    it('reports every issue on a line, worst first', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 8], ['B-2', 5]]);
        $m = mdMatch(($this->invoice)([['A-1', 10, 1100], ['B-2', 5, 2000]]));

        expect($m->lines[0]->result)->toBe('qty_variance')->and($m->lines[0]->issues)->toBe(['qty_variance', 'price_variance']);
    });
});

describe('partial deliveries and over-billing', function () {
    it('matches an invoice for exactly what a partial delivery received', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 6]]);
        $m = mdMatch(($this->invoice)([['A-1', 6, 1000]]));

        expect($m->result)->toBe('matched')->and($m->lines[0]->received_qty)->toBe(6.0);
    });

    it('flags invoicing a full order when only part was delivered', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 6], ['B-2', 5]]);
        $m = mdMatch(($this->invoice)());

        expect(mdResults($m))->toBe(['qty_variance', 'matched'])->and($m->lines[0]->disputed_minor)->toBe(4000); // 4 × $10
    });

    it('adds up several partial receipts', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 6]]);
        mdGrn($this->company, 'GRN-2', 'PO-9', [['A-1', 4], ['B-2', 5]]);

        expect(mdMatch(($this->invoice)())->result)->toBe('matched');
    });

    it('over-billing guard: quantity invoiced across invoices may not exceed quantity received', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 6]]);
        $first = mdMatch(($this->invoice)([['A-1', 6, 1000]], ['document_number' => 'INV-1']));
        expect($first->result)->toBe('matched');

        $second = mdMatch(($this->invoice)([['A-1', 4, 1000]], ['document_number' => 'INV-2']));
        expect(mdResults($second))->toBe(['qty_variance'])->and($second->lines[0]->previously_invoiced_qty)->toBe(6.0)
            ->and(collect($second->discrepancies)->firstWhere('line_no', 1)['message'])->toContain('earlier invoices');

        // The next delivery makes the second invoice good.
        mdGrn($this->company, 'GRN-2', 'PO-9', [['A-1', 4]]);
        expect(mdMatch($second->invoice)->result)->toBe('matched');
    });

    it('does not count invoices still in exception (or rejected) as billed', function () {
        ($this->po)();
        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 6]]);
        $disputed = mdMatch(($this->invoice)([['A-1', 6, 1300]], ['document_number' => 'INV-1'])); // price variance → exception
        expect($disputed->result)->toBe('exception');

        expect(mdMatch(($this->invoice)([['A-1', 6, 1000]], ['document_number' => 'INV-2']))->result)->toBe('matched');
    });
});

describe('line pairing', function () {
    it('pairs by description when the vendor prints its own SKU', function () {
        mdPo($this->company, 'PO-9', [['BL-PAP-A4', 20, 4500, 'A4 copy paper 80gsm, box of 5 reams'], ['BL-TON-26', 4, 8900, 'Toner cartridge 26A black']], ['vendor_id' => $this->vendor->id]);
        $m = mdMatch(($this->invoice)([['PAPA4-5', 20, 4500, 'Copy paper A4 80gsm (5-ream box)'], ['TN26A', 4, 8900, 'Black toner cartridge 26A']]));

        expect($m->result)->toBe('matched')->and($m->lines->pluck('method')->all())->toBe(['description', 'description'])
            ->and($m->lines[0]->poLine->sku)->toBe('BL-PAP-A4')->and($m->lines[0]->similarity)->toBeGreaterThan(0.5);
    });

    it('one-to-many: several invoice lines can bill one PO line, within its quantity', function () {
        ($this->po)();
        $m = mdMatch(($this->invoice)([['A-1', 4, 1000], ['A-1', 6, 1000]]));
        expect($m->result)->toBe('matched')->and($m->lines->pluck('po_line_id')->unique())->toHaveCount(1);

        mdPo($this->company, 'PO-OTM', [['A-1', 10, 1000]], ['vendor_id' => $this->vendor->id]);
        $over = mdMatch(($this->invoice)([['A-1', 4, 1000], ['A-1', 7, 1000]], ['document_number' => 'INV-OVER', 'po_number' => 'PO-OTM']));
        expect(mdResults($over))->toBe(['matched', 'qty_variance']);
    });

    it('partial lines: billing part of a PO line is fine', function () {
        ($this->po)();
        expect(mdMatch(($this->invoice)([['A-1', 3, 1000]]))->result)->toBe('matched');
    });
});

describe('finding the PO', function () {
    it('finds the PO by vendor and amount when the invoice has no PO number', function () {
        ($this->po)();
        mdPo($this->company, 'PO-OTHER', [['Z-1', 1, 99900]], ['vendor_id' => $this->vendor->id]);
        $m = mdMatch(($this->invoice)(attrs: ['po_number' => null]));

        expect($m->po_method)->toBe('vendor_amount')->and($m->purchaseOrder->po_number)->toBe('PO-9')
            ->and($m->po_confidence)->toBeGreaterThanOrEqual(0.75)->and($m->result)->toBe('matched');
    });

    it('does not guess between two POs that fit equally well', function () {
        ($this->po)();
        mdPo($this->company, 'PO-10', [['A-1', 10, 1000], ['B-2', 5, 2000]], ['vendor_id' => $this->vendor->id]);
        $m = mdMatch(($this->invoice)(attrs: ['po_number' => null]));

        expect(($this->codes)($m))->toBe(['po_not_found'])->and($m->tolerances['po_candidates'])->toHaveCount(2);
    });

    it('reports a missing PO, a closed PO, a vendor mismatch and a currency mismatch', function () {
        expect(($this->codes)(mdMatch(($this->invoice)())))->toBe(['po_not_found']);

        $other = mdVendor($this->company, 'Someone Else');
        ($this->po)(['status' => 'closed', 'vendor_id' => $other->id, 'currency' => 'EUR']);
        expect(($this->codes)(mdMatch(($this->invoice)(attrs: ['document_number' => 'INV-2']))))->toBe(['po_closed', 'vendor_mismatch', 'currency_mismatch']);
    });

    it('flags the invoice total when it is off from PO prices by more than the total tolerance', function () {
        ($this->po)();
        // Each line within price tolerance (+1.5%), but an extra $5 charge sits in the subtotal.
        $m = mdMatch(($this->invoice)([['A-1', 10, 1015], ['B-2', 5, 2000]], ['subtotal_minor' => 20650]));

        expect(($this->codes)($m))->toBe(['total_variance'])->and($m->totals['delta_minor'])->toBe(650)->and($m->totals['disputed_minor'])->toBe(650);
    });
});

describe('tolerance settings per company', function () {
    $set = fn (Company $c, array $m) => $c->update(['settings' => ['matching' => $m]]);

    it('allows a quantity overrun within qty_tolerance_pct', function () use ($set) {
        ($this->po)();
        $set($this->company, ['qty_tolerance_pct' => 20]);
        expect(mdMatch(($this->invoice)([['A-1', 12, 1000]]))->result)->toBe('matched');
    });

    it('uses a wider price tolerance when configured', function () use ($set) {
        ($this->po)();
        $set($this->company, ['price_tolerance_pct' => 15]);
        expect(mdMatch(($this->invoice)([['A-1', 10, 1100], ['B-2', 5, 2000]], ['subtotal_minor' => 21000]))->lines[0]->result)->toBe('matched');
    });

    it('two_way mode ignores receipts; three_way mode requires them', function () use ($set) {
        ($this->po)();
        $set($this->company, ['mode' => 'three_way']);
        expect(mdResults(mdMatch(($this->invoice)())))->toBe(['not_received', 'not_received']);

        mdGrn($this->company, 'GRN-1', 'PO-9', [['A-1', 2]]);
        $set($this->company->fresh(), ['mode' => 'two_way']);
        expect(mdMatch(($this->invoice)(attrs: ['document_number' => 'INV-2']))->kind)->toBe('two_way');
    });

    it('is isolated per company', function () use ($set) {
        ($this->po)();
        $set(Company::factory()->create(), ['qty_tolerance_pct' => 50]);
        expect(mdMatch(($this->invoice)([['A-1', 12, 1000]]))->result)->toBe('exception');
    });
});

it('keeps earlier match runs as history', function () {
    ($this->po)();
    $inv = ($this->invoice)([['A-1', 12, 1000]]);
    $first = mdMatch($inv);
    mdPo($this->company, 'PO-9', [['A-1', 12, 1000], ['B-2', 5, 2000]], ['vendor_id' => $this->vendor->id]); // PO amended in the ERP
    $second = mdMatch($inv);

    expect($first->fresh()->is_current)->toBeFalse()->and($second->is_current)->toBeTrue()->and($second->result)->toBe('matched')
        ->and($inv->fresh()->status)->toBe(DocumentStatus::Matched);
});
