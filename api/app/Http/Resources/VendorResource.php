<?php

namespace App\Http\Resources;

use App\Models\Vendor;
use Illuminate\Http\Request;

/** @mixin Vendor */
class VendorResource extends JsonApiResource
{
    public function type(): string
    {
        return 'vendors';
    }

    public function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'email' => $this->email,
            'default_currency' => $this->default_currency,
            'erp_ref' => $this->erp_ref,
            'aliases' => $this->aliases ?? [],
            'source' => $this->source,
            'documents_count' => $this->whenCounted('documents'),
            'hints_count' => $this->whenCounted('hints'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
