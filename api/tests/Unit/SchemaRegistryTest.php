<?php

use App\Services\Llm\SchemaRegistry;

it('loads versioned schemas for every document type', function (string $type, array $fields) {
    $schema = app(SchemaRegistry::class)->plain($type);

    expect($schema['$id'])->toBe("https://extractiq.test/schemas/$type.v1.json")
        ->and(array_keys($schema['properties']))->toContain(...$fields)
        ->and(app(SchemaRegistry::class)->id($type))->toBe("$type.v1");
})->with([
    ['invoice', ['vendor', 'invoice_number', 'invoice_date', 'due_date', 'po_number', 'currency', 'subtotal', 'tax_total', 'shipping', 'total', 'payment_terms', 'bank', 'line_items']],
    ['purchase_order', ['vendor', 'po_number', 'order_date', 'delivery_date', 'ship_to', 'currency', 'subtotal', 'tax_total', 'shipping', 'total', 'line_items']],
    ['goods_receipt', ['vendor', 'grn_number', 'receipt_date', 'po_number', 'received_by', 'line_items']],
]);

it('derives a strict extraction schema: every field is {value, confidence, source}', function () {
    $s = app(SchemaRegistry::class)->extraction('invoice');

    expect($s['properties']['invoice_number']['properties'])->toHaveKeys(['value', 'confidence', 'source'])
        ->and($s['properties']['vendor']['properties']['tax_id']['required'])->toBe(['value', 'confidence', 'source'])
        ->and($s['properties']['bank']['properties']['swift']['properties']['value']['type'])->toBe(['string', 'null'])
        ->and(array_keys($s['properties']['line_items']['items']['properties']))->toBe(['line_no', 'sku', 'description', 'qty', 'uom', 'unit_price', 'tax_rate', 'amount', 'confidence', 'source']);

    // Strict modes: every object closes additionalProperties and requires all its properties; no format/pattern keywords.
    $walk = function (array $node) use (&$walk) {
        expect($node)->not->toHaveKeys(['format', 'pattern', '$schema']);
        if (($node['type'] ?? null) === 'object') {
            expect($node['additionalProperties'])->toBeFalse()->and($node['required'])->toBe(array_keys($node['properties']));
        }
        foreach ($node['properties'] ?? [] as $child) {
            $walk($child);
        }
        if (isset($node['items'])) {
            $walk($node['items']);
        }
    };
    foreach (SchemaRegistry::TYPES as $type) {
        $walk(app(SchemaRegistry::class)->extraction($type));
    }
});

it('validates replies and unwraps them into values plus field meta', function () {
    $reg = app(SchemaRegistry::class);
    $reply = fixtureReply('clean');

    expect($reg->validate($reply, $reg->extraction('invoice')))->toBe([]);
    [$data, $meta] = $reg->unwrap('invoice', $reply);
    expect($data['invoice_number'])->toBe('INV-K-2041')
        ->and($data['vendor']['name'])->toBe('Kestrel Industrial Parts LLC')
        ->and($data['line_items'][0])->not->toHaveKey('confidence')
        ->and($meta['invoice_number']['source'])->toBe(['p1.l5'])
        ->and($meta)->toHaveKeys(['vendor.tax_id', 'line_items.0'])
        ->and($reg->validate($data, $reg->plain('invoice')))->toBe([]);

    unset($reply['total']);
    expect($reg->validate($reply, $reg->extraction('invoice')))->not->toBeEmpty();
    expect($reg->validate(['invoice_date' => 'next tuesday'] + $data, $reg->plain('invoice')))->toContain('/invoice_date: The data must match the \'date\' format');
});
