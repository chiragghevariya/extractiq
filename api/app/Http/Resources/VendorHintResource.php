<?php

namespace App\Http\Resources;

use App\Models\VendorHint;
use Illuminate\Http\Request;

/** @mixin VendorHint */
class VendorHintResource extends JsonApiResource
{
    public function type(): string
    {
        return 'vendor-hints';
    }

    public function fields(Request $request): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'hint' => $this->hint,
            'example_value' => $this->example_value,
            'times_used' => $this->times_used,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'source_document_id' => $this->source_document_id ? (string) $this->source_document_id : null,
            'created_by_name' => $this->whenLoaded('author', fn () => $this->author?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
