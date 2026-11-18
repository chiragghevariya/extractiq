<?php

namespace App\Support;

/**
 * Masks bank details wherever they could leak: logs, audit exports, API responses for viewers.
 * Keeps enough to recognise the account (country + check digits and the last four) and nothing
 * that would let someone pay into or impersonate it.
 */
final class Pii
{
    // IBAN: 2 letters, 2 digits, 11–30 alphanumerics, optionally grouped in fours.
    private const IBAN = '/\b([A-Z]{2}\d{2})((?:[ ]?[A-Z0-9]{4}){2,7})((?:[ ]?[A-Z0-9]{1,4}))\b/';
    // An account / routing number after its label (digits, maybe grouped), a SWIFT/BIC after its label;
    // long digit runs (8–20) on their own.
    private const LABELLED = '/\b(account(?:\s*(?:no\.?|number|#))?|acct\.?|konto(?:nummer)?|routing(?:\s*number)?|sort\s*code)(\s*[:#]?\s*)(\d[\d \-]{4,32}\d)/i';
    private const SWIFT = '/\b(swift(?:\s*\/\s*bic)?|bic)(\s*[:#]?\s*)([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/i';
    private const DIGITS = '/(?<![\d.,\-\/])(\d[\d ]{6,22}\d)(?![\d.,\-\/])/';

    public static function mask(string $text): string
    {
        $text = (string) preg_replace_callback(self::IBAN, fn ($m) => $m[1].' •••• '.self::tail($m[2].$m[3]), $text);
        foreach ([self::LABELLED, self::SWIFT] as $re) {
            $text = (string) preg_replace_callback($re, fn ($m) => $m[1].$m[2].'•••• '.self::tail($m[3]), $text);
        }

        return (string) preg_replace_callback(self::DIGITS, function ($m) {
            $digits = preg_replace('/\D/', '', $m[1]);

            // Dates, amounts and short references stay readable; 8+ digit runs are account-like.
            return strlen((string) $digits) >= 8 ? '•••• '.substr((string) $digits, -4) : $m[1];
        }, $text);
    }

    /** Mask every string in a nested array (log context, JSON payloads). */
    public static function maskArray(array $data): array
    {
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) {
                $v = self::mask($v);
            }
        });

        return $data;
    }

    private static function tail(string $s): string
    {
        return substr((string) preg_replace('/[^A-Z0-9]/i', '', $s), -4);
    }
}
