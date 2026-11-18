<?php

use App\Models\DocumentPage;
use App\Services\Llm\SourceResolver;

function fixturePages(string $name)
{
    $fx = json_decode(file_get_contents(base_path("tests/Fixtures/extraction/$name/ocr.json")), true);

    return collect($fx['pages'])->map(fn ($p) => (new DocumentPage)->forceFill(['page_number' => $p['page_number'], 'ocr' => $p['ocr']]));
}

it('maps a value and its cited line to exact word ids', function () {
    $pages = fixturePages('clean');
    $words = $pages[0]->ocr['words'];
    $r = new SourceResolver($pages);

    $hit = $r->resolve('INV-K-2041', ['p1.l5']);
    expect($hit['words'])->toHaveCount(1)->and($words[(int) substr($hit['words'][0], 4)]['text'])->toBe('INV-K-2041')
        ->and($hit['ocr_confidence'])->toBe(1.0);

    $total = $r->resolve(615.6, []); // amounts normalise (615.6 → 615.60); no line cited → whole page searched
    expect($words[(int) substr($total['words'][0], 4)]['text'])->toBe('615.60');

    $vendor = $r->resolve('Kestrel Industrial Parts LLC', []);
    expect($vendor['words'])->toHaveCount(4);
});

it('reports the OCR confidence of the source words (low on a scan)', function () {
    $r = new SourceResolver(fixturePages('low_confidence'));
    $date = $r->resolve('2026-11-13', []);

    expect($date['words'])->not->toBeEmpty()->and($date['ocr_confidence'])->toBeLessThan(0.9);
});

it('returns nothing for values that are not on the page', function () {
    expect((new SourceResolver(fixturePages('clean')))->resolve('NOT-THERE-999', ['p1.l3'])['words'])->toBe([]);
});
