<?php

use App\Services\Llm\Drivers\HeuristicLlm;
use App\Services\Llm\ExtractionRequest;
use App\Services\Llm\SchemaRegistry;
use App\Services\Ocr\Normalizer;

function rowsOf(string $fixture): array
{
    $fx = json_decode(file_get_contents(base_path("tests/Fixtures/extraction/$fixture/ocr.json")), true);

    return collect($fx['pages'])->flatMap(fn ($p) => Normalizer::rows($p['page_number'], $p['ocr']['lines']))->all();
}

it('replies in exactly the extraction schema, citing line ids', function () {
    $reg = app(SchemaRegistry::class);
    $reply = (new HeuristicLlm)->extract(new ExtractionRequest('invoice', 'invoice.v1', $reg->extraction('invoice'), '', '', rowsOf('clean')))->reply;

    expect($reg->validate($reply, $reg->extraction('invoice')))->toBe([])
        ->and($reply['invoice_number'])->toMatchArray(['value' => 'INV-K-2041'])
        ->and($reply['invoice_number']['source'][0])->toMatch('/^p1\.l\d+$/')
        ->and($reply['vendor']['tax_id']['value'])->toBe('88-1234567')
        ->and($reply['subtotal']['value'])->toBe(570.0)
        ->and($reply['tax_total']['value'])->toBe(45.6)
        ->and($reply['line_items'])->toHaveCount(3)
        ->and($reply['line_items'][2])->toMatchArray(['line_no' => 3, 'sku' => 'KIP-2040', 'qty' => 40.0, 'unit_price' => 6.75, 'amount' => 270.0]);
});

it('uses a learned vendor hint label for a field', function () {
    $rows = [['ids' => ['p1.l1'], 'text' => 'INVOICE'], ['ids' => ['p1.l2'], 'text' => 'Order Ref: PO-1005']];
    $req = fn ($hints) => new ExtractionRequest('invoice', 'invoice.v1', [], '', '', $rows, [], $hints);

    expect((new HeuristicLlm)->extract($req([]))->reply['po_number']['value'])->toBeNull();
    $withHint = (new HeuristicLlm)->extract($req([['field' => 'po_number', 'hint' => 'PO number appears as "Order Ref".', 'label' => 'Order Ref']]))->reply;
    expect($withHint['po_number'])->toMatchArray(['value' => 'PO-1005', 'source' => ['p1.l2']]);
});

it('classifies by title', function () {
    expect((new HeuristicLlm)->classify("GOODS RECEIVED NOTE\nGRN-1")['type'])->toBe('goods_receipt')
        ->and((new HeuristicLlm)->classify('hello')['type'])->toBe('unknown');
});

it('reads a German invoice: labels, dd.mm.yyyy dates and 1.234,56 amounts', function () {
    $rows = array_map(fn ($t, $i) => ['ids' => ["p1.l$i"], 'text' => $t], [
        'RECHNUNG  Harbor & Pine Packaging Ltd',
        'Rechnungsnummer: HP-3006',
        'Rechnungsdatum: 13.10.2026',
        'Zahlbar bis: 12.11.2026',
        'Bestellnummer: PO-2006',
        'USt-IdNr.: DE123456789',
        'Währung: EUR',
        'HP-BOX-S     Wellpappkarton klein 300x200x150      655       0,85      556,75',
        'HP-WRAP-50   Stretchfolie 500mm x 300m              26      18,40      478,40',
        'Zwischensumme: 1.035,15',
        'MwSt. (19%): 196,68',
        'Gesamtbetrag: 1.231,83 EUR',
    ], range(1, 12));
    $reply = (new HeuristicLlm)->extract(new ExtractionRequest('invoice', 'invoice.v1', [], '', '', $rows))->reply;

    expect($reply['invoice_number']['value'])->toBe('HP-3006')
        ->and($reply['invoice_date']['value'])->toBe('2026-10-13')
        ->and($reply['due_date']['value'])->toBe('2026-11-12')
        ->and($reply['po_number']['value'])->toBe('PO-2006')
        ->and($reply['vendor']['tax_id']['value'])->toBe('DE123456789')
        ->and($reply['currency']['value'])->toBe('EUR')
        ->and($reply['subtotal']['value'])->toBe(1035.15)
        ->and($reply['tax_total']['value'])->toBe(196.68)
        ->and($reply['total']['value'])->toBe(1231.83)
        ->and($reply['line_items'])->toHaveCount(2)
        ->and($reply['line_items'][1])->toMatchArray(['sku' => 'HP-WRAP-50', 'qty' => 26.0, 'unit_price' => 18.4, 'amount' => 478.4])
        ->and((new HeuristicLlm)->classify("RECHNUNG\nRechnungsnummer: HP-1")['type'])->toBe('invoice');
});

it('falls back to the letterhead for the vendor, never the bill-to party', function () {
    $rows = [
        ['ids' => ['p1.l0'], 'text' => 'Brightline Office Supply Co.  Invoice'],
        ['ids' => ['p1.l1'], 'text' => 'Invoice No  INV-BL-3004  Invoice Date  6 Nov 2026'],
        ['ids' => ['p1.l2'], 'text' => 'Bill To  Demo Manufacturing Co.'],
    ];
    $reply = (new HeuristicLlm)->extract(new ExtractionRequest('invoice', 'invoice.v1', [], '', '', $rows))->reply;

    expect($reply['vendor']['name'])->toMatchArray(['value' => 'Brightline Office Supply Co.', 'source' => ['p1.l0']])
        ->and($reply['invoice_number']['value'])->toBe('INV-BL-3004')
        ->and($reply['invoice_date']['value'])->toBe('2026-11-06');

    $billToOnly = [['ids' => ['p1.l0'], 'text' => 'INVOICE'], ['ids' => ['p1.l1'], 'text' => 'Bill To: Demo Manufacturing Co.']];
    expect((new HeuristicLlm)->extract(new ExtractionRequest('invoice', 'invoice.v1', [], '', '', $billToOnly))->reply['vendor']['name']['value'])->toBeNull();
});
