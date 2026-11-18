<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Vendor;
use App\Services\Pipeline\DocumentValidator;

beforeEach(function () {
    $this->company = demoCompany();
    $this->vendor = Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Acme Test Supplies', 'tax_id' => '12-3456789']);
    $this->make = fn (array $attrs = [], array $lines = [['A-1', 2, 5000]]) => Document::factory()->withLines($lines)
        ->create($attrs + ['company_id' => $this->company->id, 'vendor_id' => $this->vendor->id, 'po_number' => 'PO-1']);
    $this->run = fn (Document $d, bool $reviewed = false) => app(DocumentValidator::class)->validate($d->fresh(), $reviewed);
    $this->rule = fn (array $r, string $rule) => collect($r['results'])->where('rule', $rule)->values()->all();
});

it('returns pass | warn | fail with a message for every rule', function () {
    $r = ($this->run)(($this->make)());

    expect($r['status'])->toBe(DocumentStatus::Validated)->and($r['errors'])->toBe([]);
    foreach ($r['results'] as $res) {
        expect($res['status'])->toBeIn(['pass', 'warn', 'fail'])->and($res['message'])->not->toBeEmpty();
    }
    expect(collect($r['results'])->pluck('rule')->all())->toContain('required', 'line_math', 'lines_sum', 'total_math', 'duplicate');
});

it('fails line amounts that are not qty × unit price (±0.01)', function () {
    $ok = ($this->make)([], [['A-1', 3, 333]]);                       // 3 × 3.33 = 9.99
    expect(($this->rule)(($this->run)($ok), 'line_math')[0]['status'])->toBe('pass');

    $doc = ($this->make)();
    $doc->lines()->withoutGlobalScopes()->first()->update(['amount_minor' => 10002]);
    $res = ($this->rule)(($this->run)($doc), 'line_math')[0];
    expect($res['status'])->toBe('fail')->and($res['message'])->toContain('Line 1');
});

it('fails when lines do not sum to the subtotal, or subtotal + tax + shipping ≠ total', function () {
    expect(($this->rule)(($this->run)(($this->make)(['subtotal_minor' => 9000, 'total_minor' => 9800])), 'lines_sum')[0]['status'])->toBe('fail');

    $shipping = ($this->make)(['subtotal_minor' => 10000, 'tax_minor' => 800, 'shipping_minor' => 1500, 'total_minor' => 12300]);
    expect(($this->rule)(($this->run)($shipping), 'total_math')[0]['status'])->toBe('pass');
    $bad = ($this->make)(['subtotal_minor' => 10000, 'tax_minor' => 800, 'shipping_minor' => 1500, 'total_minor' => 10800]);
    expect(($this->rule)(($this->run)($bad), 'total_math')[0]['status'])->toBe('fail');
});

it('fails a due date before the invoice date', function () {
    $r = ($this->run)(($this->make)(['document_date' => '2026-05-10', 'due_date' => '2026-05-01']));

    expect(($this->rule)($r, 'due_after_issue')[0]['status'])->toBe('fail')->and($r['status'])->toBe(DocumentStatus::NeedsReview);
});

it('checks tax id formats (EIN, EU VAT, …)', function (?string $taxId, ?string $status) {
    $doc = ($this->make)();
    $doc->update(['extraction' => ['data' => ['vendor' => ['tax_id' => $taxId]], 'fields' => []]]);
    $res = ($this->rule)(($this->run)($doc), 'tax_id_format');

    expect($res[0]['status'] ?? null)->toBe($status);
})->with([
    ['88-1234567', 'pass'], ['DE123456789', 'pass'], ['GB123456789', 'pass'], ['27AAPFU0939F1ZV', 'pass'],
    ['US-88-1234567', 'warn'], ['12345', 'warn'],
]);

it('fails a duplicate invoice number from the same vendor (the later copy only)', function () {
    $first = ($this->make)(['document_number' => 'INV-77']);
    $second = ($this->make)(['document_number' => 'inv-77']);

    expect(($this->rule)(($this->run)($second), 'duplicate')[0]['status'])->toBe('fail')
        ->and(($this->rule)(($this->run)($first), 'duplicate')[0]['status'])->toBe('pass');
});

it('needs review when any extracted field is below 0.90, even with no failed rules', function () {
    $doc = ($this->make)(['extraction' => ['fields' => [
        'invoice_number' => ['confidence' => 0.98, 'has_value' => true],
        'invoice_date' => ['confidence' => 0.74, 'has_value' => true],
        'shipping' => ['confidence' => 0.0, 'has_value' => false],     // absent fields don't count
    ]]]);

    $r = ($this->run)($doc);
    expect($r['errors'])->toBe([])->and($r['status'])->toBe(DocumentStatus::NeedsReview)
        ->and(($this->rule)($r, 'confidence')[0])->toMatchArray(['status' => 'warn'])
        ->and(($this->rule)($r, 'confidence')[0]['message'])->toContain('Invoice date (74%)');

    // Once a person has reviewed it, their confirmation stands in for confidence.
    expect(($this->run)($doc, true)['status'])->toBe(DocumentStatus::Validated);
});

it('warns (does not fail) for an unknown vendor and a missing PO', function () {
    $r = ($this->run)(($this->make)(['vendor_id' => null, 'vendor_name' => 'Somebody New', 'po_number' => null]));

    expect(collect($r['warnings'])->pluck('code'))->toContain('vendor_known', 'missing_po')->and($r['status'])->toBe(DocumentStatus::Validated);
});

it('fails missing required fields and needs no money on goods receipts', function () {
    $r = ($this->run)(($this->make)(['document_number' => null, 'total_minor' => null]));
    expect(collect($r['errors'])->pluck('code')->unique()->values()->all())->toBe(['required']);

    $grn = ($this->make)(['type' => DocumentType::GoodsReceipt, 'total_minor' => null, 'subtotal_minor' => null, 'tax_minor' => null], [['A-1', 2, null]]);
    expect(($this->run)($grn)['errors'])->toBe([]);
});
