<?php

namespace App\Http\Resources;

use App\Models\DocumentPage;
use Illuminate\Http\Request;

/** @mixin DocumentPage */
class PageResource extends JsonApiResource
{
    public function type(): string
    {
        return 'document-pages';
    }

    public function fields(Request $request): array
    {
        return [
            'page_number' => $this->page_number,
            'width' => $this->width,
            'height' => $this->height,
            'dpi' => $this->dpi,
            'has_image' => $this->image_path !== null,
            'word_count' => count($this->ocr['words'] ?? []),
            'key_value_count' => count($this->ocr['key_values'] ?? []),
            'table_count' => count($this->ocr['tables'] ?? []),
            'image_mime' => $this->image_path ? (str_ends_with($this->image_path, '.png') ? 'image/png' : (str_ends_with($this->image_path, '.jpg') ? 'image/jpeg' : 'image/tiff')) : null,
            'text' => $this->text,
            'char_count' => $this->char_count,
            'confidence' => $this->confidence,
            'ocr_provider' => $this->ocr_provider,
        ];
    }
}
