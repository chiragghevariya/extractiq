<?php

use App\Support\Money;

it('parses amounts into minor units', function (mixed $in, ?string $cur, ?int $out) {
    expect(Money::toMinor($in, $cur))->toBe($out);
})->with([
    ['1,234.56', 'USD', 123456],
    ['1.234,56', 'EUR', 123456],
    ['$ 99.9', 'USD', 9990],
    ['1,234', 'USD', 123400],
    [12.5, 'USD', 1250],
    ['500', 'JPY', 500],
    ['1.250', 'KWD', 1250],
    [null, 'USD', null],
    ['', 'USD', null],
    ['-15.00', 'USD', -1500],
]);

it('formats minor units', function () {
    expect(Money::format(123456, 'USD'))->toBe('1,234.56 USD')
        ->and(Money::format(500, 'JPY'))->toBe('500 JPY')
        ->and(Money::format(null, 'USD'))->toBeNull();
});
