<?php

namespace App\Http\Resources;

use App\Models\DocumentEvent;
use Illuminate\Http\Request;

/** @mixin DocumentEvent */
class EventResource extends JsonApiResource
{
    public function type(): string
    {
        return 'document-events';
    }

    public function fields(Request $request): array
    {
        return [
            'step' => $this->step,
            'status' => $this->status,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'duration_ms' => $this->duration_ms,
            'attempt' => $this->attempt,
            'error' => $this->error,
            'meta' => $this->meta,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'document_filename' => $this->whenLoaded('document', fn () => $this->document?->original_filename),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function relationships(Request $request): array
    {
        return ['document' => ['data' => self::identifier('documents', $this->document_id)]];
    }
}
