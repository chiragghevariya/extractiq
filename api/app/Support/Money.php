<?php

namespace App\Support;

/** Money helpers. Amounts are always integer minor units + ISO 4217 code. */
final class Money
{
    /** Currencies with a non-2 minor unit exponent. */
    private const EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'VND' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3];

    public static function exponent(?string $currency): int
    {
        return self::EXPONENTS[strtoupper((string) $currency)] ?? 2;
    }

    /** Parse "1,234.56", "1.234,56", "$1 234.5" or a float into minor units. */
    public static function toMinor(mixed $value, ?string $currency = 'USD'): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (int) round($value * (10 ** self::exponent($currency)));
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($s === '' || $s === '-') {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // whichever separator comes last is the decimal separator
            $s = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $s)
                : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            $decimals = strlen($s) - $lastComma - 1;
            $s = $decimals === 3 && substr_count($s, ',') >= 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }

        return (int) round(((float) $s) * (10 ** self::exponent($currency)));
    }

    public static function format(?int $minor, ?string $currency): ?string
    {
        if ($minor === null) {
            return null;
        }
        $exp = self::exponent($currency);

        return number_format($minor / (10 ** $exp), $exp, '.', ',').' '.strtoupper((string) $currency);
    }
}
