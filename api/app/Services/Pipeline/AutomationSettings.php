<?php

namespace App\Services\Pipeline;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Support\Money;

/**
 * Touchless processing (per company, Settings): a validated invoice from a known vendor whose total
 * is below the limit is approved without review; if it then matches its PO it goes straight to the
 * ERP. Anything that doesn't match still stops in Matching for a person.
 */
final class AutomationSettings
{
    public function __construct(public readonly bool $touchless, public readonly float $maxTotal) {}

    public static function for(Company|int $company): self
    {
        $company = $company instanceof Company ? $company : Company::findOrFail($company);
        $s = (array) ($company->settings['automation'] ?? []);

        return new self((bool) ($s['touchless'] ?? false), (float) ($s['touchless_max_total'] ?? 1000));
    }

    public function toArray(): array
    {
        return ['touchless' => $this->touchless, 'touchless_max_total' => $this->maxTotal];
    }

    /** Why this document may skip review, or null. */
    public function appliesTo(Document $doc): bool
    {
        return $this->touchless
            && $doc->type === DocumentType::Invoice
            && $doc->vendor_id !== null
            && $doc->total_minor !== null
            && $doc->total_minor < (Money::toMinor($this->maxTotal, $doc->currency) ?? 0);
    }
}
