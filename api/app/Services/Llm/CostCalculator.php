<?php

namespace App\Services\Llm;

/** Token cost per extraction from the per-model price table in config/extractiq.php (USD per million tokens). */
class CostCalculator
{
    public function cost(?string $model, ?int $input, ?int $output): ?float
    {
        if (! $model || ($input === null && $output === null)) {
            return null;
        }
        $prices = config('extractiq.extraction.prices');
        $price = $prices[$model] ?? collect($prices)->first(fn ($p, $k) => str_starts_with($model, $k));
        if (! $price) {
            return null;
        }

        return round((($input ?? 0) * $price['input'] + ($output ?? 0) * $price['output']) / 1_000_000, 6);
    }
}
