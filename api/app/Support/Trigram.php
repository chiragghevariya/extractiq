<?php

namespace App\Support;

/**
 * Trigram similarity, computed the way PostgreSQL's pg_trgm does it: lower-case, split
 * into alphanumeric words, pad each as "  word ", take the set of 3-character windows,
 * similarity = shared / union. Used in PHP for line descriptions and as the fallback for
 * vendor names when the database isn't Postgres (tests run on SQLite).
 */
class Trigram
{
    /** @return array<string, true> */
    public static function grams(string $text): array
    {
        $out = [];
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);
        foreach ($m[0] as $word) {
            $padded = '  '.$word.' ';
            $len = mb_strlen($padded);
            for ($i = 0; $i + 3 <= $len; $i++) {
                $out[mb_substr($padded, $i, 3)] = true;
            }
        }

        return $out;
    }

    public static function similarity(string $a, string $b): float
    {
        $ga = self::grams($a);
        $gb = self::grams($b);
        if (! $ga || ! $gb) {
            return 0.0;
        }
        $shared = count(array_intersect_key($ga, $gb));

        return round($shared / (count($ga) + count($gb) - $shared), 4);
    }
}
