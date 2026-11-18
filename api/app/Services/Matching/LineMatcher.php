<?php

namespace App\Services\Matching;

use App\Models\PoLine;
use App\Support\Trigram;
use Illuminate\Support\Collection;

/**
 * Pairs document lines (invoice or goods-receipt lines) with purchase-order lines.
 *
 *   1. SKU, compared as upper-case alphanumerics. Several PO lines with that SKU: closest price.
 *   2. Otherwise description similarity (trigram) blended with price and quantity proximity:
 *        score = 0.6 × description + 0.25 × price + 0.15 × quantity
 *      kept when description ≥ 0.3 and score ≥ 0.5. Vendors often print their own SKUs, so a
 *      SKU mismatch doesn't rule a pair out.
 *
 * Assignment is greedy by score with each PO line used once; lines left over may then share an
 * already-used PO line, which is how one PO line is billed across several invoice lines
 * (one-to-many). Partial quantities are fine here — quantities are judged by the engine.
 */
class LineMatcher
{
    public const DESCRIPTION_MIN = 0.3;

    public const SCORE_MIN = 0.5;

    /**
     * @param  list<object>  $lines  objects with sku, description, quantity and (optionally) unit_price_minor
     * @param  Collection<int, PoLine>  $poLines
     * @return list<array{po: ?PoLine, method: string, score: ?float}> indexed like $lines
     */
    public function pair(array $lines, Collection $poLines): array
    {
        $out = array_fill(0, count($lines), ['po' => null, 'method' => 'none', 'score' => null]);
        $used = [];

        foreach ($lines as $i => $line) {
            $key = self::skuKey($line->sku ?? null);
            if ($key === '') {
                continue;
            }
            $same = $poLines->filter(fn (PoLine $p) => self::skuKey($p->sku) === $key);
            if ($same->isEmpty()) {
                continue;
            }
            $pick = $same->sortBy(fn (PoLine $p) => [isset($used[$p->id]) ? 1 : 0, abs(($p->unit_price_minor ?? 0) - ($line->unit_price_minor ?? $p->unit_price_minor ?? 0))])->first();
            $out[$i] = ['po' => $pick, 'method' => 'sku', 'score' => 1.0];
            $used[$pick->id] = true;
        }

        $pairs = [];
        foreach ($lines as $i => $line) {
            if ($out[$i]['po']) {
                continue;
            }
            foreach ($poLines as $p) {
                $s = $this->score($line, $p);
                if ($s !== null) {
                    $pairs[] = [$s, $i, $p];
                }
            }
        }
        usort($pairs, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ([false, true] as $shareAllowed) {
            foreach ($pairs as [$s, $i, $p]) {
                if ($out[$i]['po'] || (! $shareAllowed && isset($used[$p->id]))) {
                    continue;
                }
                $out[$i] = ['po' => $p, 'method' => 'description', 'score' => round($s, 4)];
                $used[$p->id] = true;
            }
        }

        return $out;
    }

    /** Blended score, or null when the descriptions aren't similar enough to consider. */
    public function score(object $line, PoLine $po): ?float
    {
        $desc = Trigram::similarity((string) ($line->description ?? ''), $po->description);
        if ($desc < self::DESCRIPTION_MIN) {
            return null;
        }
        $price = self::proximity($line->unit_price_minor ?? null, $po->unit_price_minor);
        $qty = self::proximity($line->quantity ?? null, $po->quantity);
        $score = 0.6 * $desc + 0.25 * $price + 0.15 * $qty;

        return $score >= self::SCORE_MIN ? $score : null;
    }

    public static function skuKey(?string $sku): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $sku));
    }

    /** 1 when equal, falling to 0 as the values diverge; 0.5 (neutral) when either is unknown. */
    private static function proximity(int|float|null $a, int|float|null $b): float
    {
        if ($a === null || $b === null) {
            return 0.5;
        }
        $max = max(abs($a), abs($b));

        return $max == 0 ? 1.0 : 1 - min(1, abs($a - $b) / $max);
    }
}
