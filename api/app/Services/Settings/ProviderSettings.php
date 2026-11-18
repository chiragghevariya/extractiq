<?php

namespace App\Services\Settings;

use App\Models\Company;

/**
 * Which OCR and LLM driver a company uses (Settings → Reading documents). Falls back to the
 * environment default; a driver that isn't configured on this server (no API key) can't be
 * chosen, and a stored choice whose key was since removed falls back to the default too.
 */
final class ProviderSettings
{
    public function __construct(public readonly string $ocr, public readonly string $llm) {}

    public static function for(Company|int $company): self
    {
        $company = $company instanceof Company ? $company : Company::findOrFail($company);
        $s = (array) ($company->settings['providers'] ?? []);
        $ocr = in_array($s['ocr'] ?? null, self::usable('ocr'), true) ? $s['ocr'] : (string) config('extractiq.ocr.default');
        $llm = in_array($s['llm'] ?? null, self::usable('llm'), true) ? $s['llm'] : (string) config('extractiq.llm.default');

        return new self($ocr, $llm);
    }

    public function toArray(): array
    {
        return ['ocr' => $this->ocr, 'llm' => $this->llm];
    }

    /** @return array<string, array{configured: bool}> every driver the server knows, and whether it can run here */
    public static function catalog(string $kind): array
    {
        $out = [];
        foreach (array_keys((array) config("extractiq.$kind.drivers")) as $driver) {
            $out[$driver] = ['configured' => self::configured($kind, $driver)];
        }

        return $out;
    }

    /** @return list<string> */
    public static function usable(string $kind): array
    {
        return array_keys(array_filter(self::catalog($kind), fn ($d) => $d['configured']));
    }

    private static function configured(string $kind, string $driver): bool
    {
        $cfg = (array) config("extractiq.$kind.drivers.$driver", []);

        return match ("$kind.$driver") {
            'ocr.textract' => filled($cfg['key'] ?? null) && filled($cfg['secret'] ?? null),
            'llm.anthropic', 'llm.openai' => filled($cfg['api_key'] ?? null),
            default => true,
        };
    }
}
