<?php

namespace App\Http\Resources;

use App\Models\DocumentLine;
use Illuminate\Http\Request;

/** @mixin DocumentLine */
class DocumentLineResource extends JsonApiResource
{
    public function type(): string
    {
        return 'document-lines';
    }

    public function fields(Request $request): array
    {
        return [
            'line_no' => $this->line_no,
            'sku' => $this->sku,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'uom' => $this->uom,
            'tax_rate' => $this->tax_rate,
            'unit_price_minor' => $this->unit_price_minor,
            'amount_minor' => $this->amount_minor,
        ];
    }
}
