<?php

use App\Services\Ocr\DocumentClassifier;

it('classifies the sample documents from their text', function (string $file, string $type) {
    $r = app(DocumentClassifier::class)->classify(sampleText($file));

    expect($r['type'])->toBe($type)->and($r['confidence'])->toBeGreaterThanOrEqual(0.6)->and($r['signals'])->not->toBeEmpty();
})->with([
    ['03-inv-k-2041-kestrel-3way-match.pdf', 'invoice'],
    ['01-po-1001-kestrel.pdf', 'purchase_order'],
    ['02-grn-5001-kestrel.pdf', 'goods_receipt'],
    ['07-grn-5003-harbor-partial.pdf', 'goods_receipt'],
    ['11-ncs-9001-nimbus-no-po.pdf', 'invoice'],
    ['invoice-hp-3006-de.pdf', 'invoice'],
    ['invoice-inv-bl-3004.pdf', 'invoice'],
    ['invoice-inv-k-3005-multipage.pdf', 'invoice'],
]);

it('stays undecided on weak or mixed evidence', function (string $text) {
    expect(app(DocumentClassifier::class)->classify($text)['type'])->toBeNull();
})->with([
    "Hello\nThanks for the meeting yesterday.",
    "Statement\nInvoice INV-1 and purchase order PO-2 attached",
]);
