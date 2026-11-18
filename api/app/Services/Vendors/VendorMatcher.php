<?php

namespace App\Services\Vendors;

use App\Models\Document;
use App\Models\Vendor;

/**
 * Finds which known vendor a document is from by looking for vendor names in the OCR
 * text — before extraction, so that vendor's learned hints can be injected.
 */
class VendorMatcher
{
    public function match(Document $document): ?Vendor
    {
        $text = ' '.Vendor::normalize(mb_substr((string) $document->ocr_text, 0, 4000)).' ';
        $best = null;
        foreach (Vendor::withoutGlobalScopes()->where('company_id', $document->company_id)->get() as $vendor) {
            $name = $vendor->normalized_name;
            if (mb_strlen($name) >= 4 && str_contains($text, " $name ") && (! $best || mb_strlen($name) > mb_strlen($best->normalized_name))) {
                $best = $vendor;
            }
        }

        return $best;
    }
}
