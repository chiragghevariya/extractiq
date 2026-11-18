<?php

namespace App\Services\Ocr;

/**
 * Cheap, explainable document-type classification from OCR text. Keywords in the
 * title area (first lines) count triple. Returns null type when the evidence is weak,
 * leaving the decision to the LLM in the extraction step (Phase 3).
 */
class DocumentClassifier
{
    private const RULES = [
        'invoice' => [
            '/\btax invoice\b/' => 4, '/\binvoice\b/' => 3, '/\binvoice (?:no|number|#|date)\b/' => 2, '/\bbill to\b/' => 1,
            '/\bamount due\b|\btotal due\b|\bbalance due\b/' => 2, '/\bdue date\b/' => 1, '/\bremit(?:tance)?\b/' => 1, '/\bplease pay\b/' => 1,
            '/\brechnung\b/' => 3, '/\brechnungs(?:nummer|datum)\b/' => 2, '/\bgesamtbetrag\b|\bzahlbar\b/' => 1,
        ],
        'purchase_order' => [
            '/\bpurchase order\b/' => 4, '/\bpo (?:date|no|number)\b/' => 1, '/\bship to\b/' => 1, '/\border date\b/' => 1,
            '/\bplease quote the po\b/' => 2, '/\bpayment terms\b/' => 1, '/\bbuyer\b/' => 1,
        ],
        'goods_receipt' => [
            '/\bgoods receiv(?:ed|ing) note\b/' => 5, '/\bgoods receipt\b/' => 4, '/\bdelivery note\b/' => 4, '/\bgrn\b/' => 2,
            '/\breceived by\b/' => 2, '/\breceipt date\b/' => 1, '/\bquantity received\b|\breceived\b/' => 1, '/\binspected\b/' => 1,
        ],
    ];

    /** @return array{type: ?string, confidence: float, scores: array<string, int>, signals: list<string>} */
    public function classify(string $text): array
    {
        $text = mb_strtolower($text);
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $title = implode("\n", array_slice(array_values(array_filter($lines, fn ($l) => trim($l) !== '')), 0, 3));

        $scores = [];
        $signals = [];
        foreach (self::RULES as $type => $rules) {
            $scores[$type] = 0;
            foreach ($rules as $re => $weight) {
                if (preg_match($re, $text, $m)) {
                    $boost = preg_match($re, $title) ? 3 : 1;
                    $scores[$type] += $weight * $boost;
                    $signals[] = "$type: \"{$m[0]}\"".($boost > 1 ? ' (title)' : '');
                }
            }
        }
        arsort($scores);
        $types = array_keys($scores);
        $top = $scores[$types[0]];
        $second = $scores[$types[1]] ?? 0;
        // Need clear evidence and a clear winner (margin scales with the score, so mixed documents stay undecided).
        if ($top < 4 || $top - $second < max(3, 0.35 * $top)) {
            return ['type' => null, 'confidence' => 0.0, 'scores' => $scores, 'signals' => $signals];
        }

        return [
            'type' => $types[0],
            'confidence' => round(min(0.99, 0.6 + 0.4 * ($top - $second) / max(1, $top)), 3),
            'scores' => $scores,
            'signals' => $signals,
        ];
    }
}
