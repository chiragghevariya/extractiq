<?php

namespace App\Services\Llm;

final class VendorChoice
{
    /** Clamp a model's vendor choice to something safe to act on. @return array{index: ?int, confidence: float, reason: string} */
    public static function sanitize(array $json, int $count): array
    {
        $index = $json['index'] ?? null;
        $index = is_int($index) && $index >= 0 && $index < $count ? $index : null;

        return [
            'index' => $index,
            'confidence' => $index === null ? 0.0 : round(max(0.0, min(1.0, (float) ($json['confidence'] ?? 0))), 4),
            'reason' => mb_substr((string) ($json['reason'] ?? ''), 0, 300),
        ];
    }
}
