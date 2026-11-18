<?php

namespace App\Services\Llm;

use App\Models\DocumentPage;
use Illuminate\Support\Collection;

/**
 * Turns the line ids a model cites into exact OCR word ids ("p1.w37") by matching the
 * value's tokens inside those lines, so the UI can highlight where each value came
 * from. If the model cited nothing (or the wrong line) the whole document is searched.
 * Also reports the OCR confidence of the matched words.
 */
class SourceResolver
{
    /** @var array<int, array{words: list<array>, lines: list<array>}> */
    private array $pages = [];

    /** @param Collection<int, DocumentPage> $pages */
    public function __construct(Collection $pages)
    {
        foreach ($pages as $p) {
            $this->pages[$p->page_number] = ['words' => $p->ocr['words'] ?? [], 'lines' => $p->ocr['lines'] ?? []];
        }
    }

    /**
     * @param  list<string>  $lineIds
     * @return array{words: list<string>, lines: list<string>, ocr_confidence: ?float}
     */
    public function resolve(mixed $value, array $lineIds): array
    {
        $tokens = $this->tokens($value);
        if (! $tokens) {
            return ['words' => [], 'lines' => $lineIds, 'ocr_confidence' => null];
        }

        $candidates = [];
        foreach ($lineIds as $id) {
            if (preg_match('/^p(\d+)\.l(\d+)$/', $id, $m) && isset($this->pages[(int) $m[1]]['lines'][(int) $m[2]])) {
                $candidates[] = [(int) $m[1], $this->pages[(int) $m[1]]['lines'][(int) $m[2]]['bbox']];
            }
        }

        $hit = $this->search($tokens, $candidates) ?? $this->search($tokens, null);
        if (! $hit) {
            return ['words' => [], 'lines' => $lineIds, 'ocr_confidence' => null];
        }
        [$page, $indexes] = $hit;
        $words = $this->pages[$page]['words'];

        return [
            'words' => array_map(fn ($i) => "p$page.w$i", $indexes),
            'lines' => $lineIds,
            'ocr_confidence' => round(min(array_map(fn ($i) => (float) $words[$i]['confidence'], $indexes)), 4),
        ];
    }

    /** @return ?array{0: int, 1: list<int>} */
    private function search(array $tokens, ?array $candidates): ?array
    {
        foreach ($this->pages as $page => $data) {
            $words = $data['words'];
            $scope = [];
            foreach ($words as $i => $w) {
                if ($candidates === null || $this->inAny($page, $w['bbox'], $candidates)) {
                    $scope[] = $i;
                }
            }
            // Sliding match of the value's tokens over consecutive word tokens.
            $flat = [];
            foreach ($scope as $i) {
                foreach ($this->tokens($words[$i]['text']) as $t) {
                    $flat[] = [$t, $i];
                }
            }
            $n = count($tokens);
            for ($s = 0; $s + $n <= count($flat); $s++) {
                $ok = true;
                for ($k = 0; $k < $n; $k++) {
                    if ($flat[$s + $k][0] !== $tokens[$k]) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return [$page, array_values(array_unique(array_map(fn ($x) => $x[1], array_slice($flat, $s, $n))))];
                }
            }
        }

        return null;
    }

    private function inAny(int $page, array $bbox, array $candidates): bool
    {
        $cx = $bbox[0] + $bbox[2] / 2;
        $cy = $bbox[1] + $bbox[3] / 2;
        foreach ($candidates as [$p, $b]) {
            if ($p === $page && $b && $cx >= $b[0] - 0.003 && $cx <= $b[0] + $b[2] + 0.003 && $cy >= $b[1] - 0.003 && $cy <= $b[1] + $b[3] + 0.003) {
                return true;
            }
        }

        return false;
    }

    /** Comparable tokens: lower-case, punctuation stripped, numbers normalised (1,156.68 → 1156.68, 615.6 → 615.60). */
    private function tokens(mixed $value): array
    {
        if ($value === null || $value === '' || is_array($value)) {
            return [];
        }
        if (is_float($value) || is_int($value)) {
            return [number_format((float) $value, 2, '.', '')];
        }
        $out = [];
        foreach (preg_split('/\s+/', mb_strtolower((string) $value)) as $t) {
            $t = trim($t, " \t\n\r\0\x0B.,:;()[]\"'");
            if ($t === '' || in_array($t, ['$', '€', '£', '₹'], true)) {
                continue;
            }
            $num = str_replace([',', '$', '€', '£', '₹'], '', $t);
            $out[] = is_numeric($num) && str_contains($num, '.') ? number_format((float) $num, 2, '.', '') : $t;
        }

        return $out;
    }
}
