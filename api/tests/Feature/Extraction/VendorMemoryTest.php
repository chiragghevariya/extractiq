<?php

use App\Models\Vendor;
use App\Models\VendorHint;
use App\Services\Llm\PromptBuilder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->vendor = Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Brightline Office Supply Co.', 'tax_id' => '77-7654321']);
    $this->reviewer = userWithRole('reviewer', $this->company);
});

it('learns from a correction and uses the hint on the next document from that vendor', function () {
    // Brightline prints the PO as "Order Ref": the offline extractor doesn't know that label.
    $first = ingestSample($this->company, '16-inv-bl-7801-order-ref.pdf');
    expect($first->po_number)->toBeNull()
        ->and(collect($first->validation['warnings'])->pluck('code'))->toContain('missing_po');

    // A reviewer fills it in → ExtractIQ finds where "PO-1005" sits in the OCR and remembers the label.
    Sanctum::actingAs($this->reviewer);
    $this->patchJson("/api/v1/documents/{$first->id}", ['po_number' => 'PO-1005'])->assertOk();

    $hint = VendorHint::withoutGlobalScopes()->sole();
    expect($hint->vendor_id)->toBe($this->vendor->id)
        ->and($hint->field)->toBe('po_number')
        ->and($hint->label)->toBe('Order Ref')
        ->and($hint->hint)->toBe('PO number appears as "Order Ref" on this vendor\'s documents.')
        ->and($first->events()->where('step', 'review')->sole()->meta['hints_learned'])->toHaveCount(1);

    // Next invoice from the same vendor: the hint is injected and the PO is found.
    $second = ingestSample($this->company, '18-inv-bl-7802-order-ref.pdf');
    expect($second->po_number)->toBe('PO-1006')
        ->and($second->extraction['hints_used'][0]['label'])->toBe('Order Ref')
        ->and($hint->fresh()->times_used)->toBeGreaterThanOrEqual(1);

    $prompt = app(PromptBuilder::class)->build($second, 'invoice', [['field' => 'po_number', 'hint' => $hint->hint, 'label' => 'Order Ref']])->prompt;
    expect($prompt)->toContain("## Vendor-specific hints (learned from earlier corrections)\n- PO number appears as \"Order Ref\"");
});

it('does not learn when the corrected value is not on the page', function () {
    $doc = ingestSample($this->company, '16-inv-bl-7801-order-ref.pdf');
    Sanctum::actingAs($this->reviewer);

    $this->patchJson("/api/v1/documents/{$doc->id}", ['po_number' => 'PO-NOT-ON-PAGE'])->assertOk();

    expect(VendorHint::withoutGlobalScopes()->count())->toBe(0);
});

it('lists and deletes a vendor\'s hints', function () {
    $hint = VendorHint::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'vendor_id' => $this->vendor->id, 'field' => 'po_number', 'label' => 'Order Ref', 'hint' => 'PO number appears as "Order Ref".']);
    Sanctum::actingAs($this->reviewer);

    $this->getJson("/api/v1/vendors/{$this->vendor->id}/hints")->assertOk()->assertJsonPath('data.0.attributes.label', 'Order Ref');
    $this->deleteJson("/api/v1/vendors/{$this->vendor->id}/hints/{$hint->id}")->assertNoContent();
    expect(VendorHint::withoutGlobalScopes()->count())->toBe(0);
});
