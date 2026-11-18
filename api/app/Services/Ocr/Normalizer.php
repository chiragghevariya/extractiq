<?php

namespace App\Services\Ocr;

/** Shared helpers so every provider produces the same normalised OCR. */
final class Normalizer
{
    /** Absolute box → [x, y, w, h] ratios (clamped, 4 dp). */
    public static function bbox(float $x0, float $y0, float $x1, float $y1, float $pageW, float $pageH): array
    {
        $c = fn (float $v) => round(max(0.0, min(1.0, $v)), 4);

        return [$c($x0 / $pageW), $c($y0 / $pageH), $c(($x1 - $x0) / $pageW), $c(($y1 - $y0) / $pageH)];
    }

    /** Union of ratio boxes. */
    public static function union(array $boxes): ?array
    {
        $boxes = array_values(array_filter($boxes));
        if (! $boxes) {
            return null;
        }
        $x0 = min(array_map(fn ($b) => $b[0], $boxes));
        $y0 = min(array_map(fn ($b) => $b[1], $boxes));
        $x1 = max(array_map(fn ($b) => $b[0] + $b[2], $boxes));
        $y1 = max(array_map(fn ($b) => $b[1] + $b[3], $boxes));

        return [round($x0, 4), round($y0, 4), round($x1 - $x0, 4), round($y1 - $y0, 4)];
    }

    /**
     * Build a line from its words.
     *
     * @param  list<array{text: string, bbox: array, confidence: float}>  $words
     */
    public static function line(array $words): array
    {
        return [
            'text' => trim(implode(' ', array_column($words, 'text'))),
            'bbox' => self::union(array_column($words, 'bbox')),
            'confidence' => $words ? round(array_sum(array_column($words, 'confidence')) / count($words), 4) : 0.0,
        ];
    }

    /**
     * Key/value pairs from "Label: value" lines — a cheap FORMS equivalent for
     * providers that don't detect forms (pdftotext, Tesseract).
     *
     * @param  list<array{text: string, bbox: ?array, confidence: float}>  $lines
     * @param  list<array{text: string, bbox: array, confidence: float}>  $words
     */
    public static function keyValuesFromLines(array $lines, array $words): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (! preg_match('/^\s*([A-Za-z][A-Za-z .#\/()%-]{1,40}?)\s*:\s*(\S.*)$/u', $line['text'], $m)) {
                continue;
            }
            $key = trim($m[1]);
            $value = trim($m[2]);
            // Boxes: words of the line split at the first one ending in ':'.
            $lineWords = array_values(array_filter($words, fn ($w) => $line['bbox'] && self::inside($w['bbox'], $line['bbox'])));
            $split = null;
            foreach ($lineWords as $i => $w) {
                if (str_contains($w['text'], ':')) {
                    $split = $i;
                    break;
                }
            }
            $keyWords = $split === null ? [] : array_slice($lineWords, 0, $split + 1);
            $valueWords = $split === null ? [] : array_slice($lineWords, $split + 1);
            $out[] = [
                'key' => $key,
                'value' => $value,
                'confidence' => $line['confidence'],
                'key_bbox' => self::union(array_column($keyWords, 'bbox')),
                'value_bbox' => self::union(array_column($valueWords, 'bbox')),
            ];
        }

        return $out;
    }

    /**
     * Reading-order text with one visual row per line: lines whose vertical centres
     * line up (e.g. table cells Tesseract reports separately) are joined left-to-right.
     *
     * @param  list<array{text: string, bbox: ?array, confidence: float}>  $lines
     */
    public static function rowsText(array $lines): string
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l['bbox'] !== null));
        usort($lines, fn ($a, $b) => ($a['bbox'][1] + $a['bbox'][3] / 2) <=> ($b['bbox'][1] + $b['bbox'][3] / 2));

        $rows = [];
        foreach ($lines as $line) {
            $cy = $line['bbox'][1] + $line['bbox'][3] / 2;
            $last = count($rows) - 1;
            if ($last >= 0 && abs($cy - $rows[$last]['cy']) <= max($line['bbox'][3], $rows[$last]['h']) * 0.6) {
                $rows[$last]['items'][] = $line;
            } else {
                $rows[] = ['cy' => $cy, 'h' => $line['bbox'][3], 'items' => [$line]];
            }
        }

        return implode("\n", array_map(function ($row) {
            usort($row['items'], fn ($a, $b) => $a['bbox'][0] <=> $b['bbox'][0]);

            return implode('  ', array_column($row['items'], 'text'));
        }, $rows));
    }

    /**
     * Visual rows with the ids of the OCR lines they contain — the line/table structure
     * the LLM sees. Ids look like "p1.l7" (page 1, index 7 in that page's lines array).
     *
     * @param  list<array{text: string, bbox: ?array, confidence: float}>  $lines
     * @return list<array{ids: list<string>, text: string}>
     */
    public static function rows(int $page, array $lines): array
    {
        $indexed = [];
        foreach ($lines as $i => $l) {
            if ($l['bbox'] !== null && trim($l['text']) !== '') {
                $indexed[] = $l + ['id' => "p$page.l$i"];
            }
        }
        usort($indexed, fn ($a, $b) => ($a['bbox'][1] + $a['bbox'][3] / 2) <=> ($b['bbox'][1] + $b['bbox'][3] / 2));

        $rows = [];
        foreach ($indexed as $line) {
            $cy = $line['bbox'][1] + $line['bbox'][3] / 2;
            $last = count($rows) - 1;
            if ($last >= 0 && abs($cy - $rows[$last]['cy']) <= max($line['bbox'][3], $rows[$last]['h']) * 0.6) {
                $rows[$last]['items'][] = $line;
            } else {
                $rows[] = ['cy' => $cy, 'h' => $line['bbox'][3], 'items' => [$line]];
            }
        }

        return array_map(function ($row) {
            usort($row['items'], fn ($a, $b) => $a['bbox'][0] <=> $b['bbox'][0]);

            return ['ids' => array_column($row['items'], 'id'), 'text' => implode('  ', array_column($row['items'], 'text'))];
        }, $rows);
    }

    private static function inside(array $b, array $outer): bool
    {
        $cx = $b[0] + $b[2] / 2;
        $cy = $b[1] + $b[3] / 2;

        return $cx >= $outer[0] - 0.002 && $cx <= $outer[0] + $outer[2] + 0.002 && $cy >= $outer[1] - 0.002 && $cy <= $outer[1] + $outer[3] + 0.002;
    }
}
