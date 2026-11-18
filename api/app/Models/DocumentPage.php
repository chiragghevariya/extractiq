<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $page_number
 * @property ?string $image_disk
 * @property ?string $image_path
 * @property ?int $width
 * @property ?int $height
 * @property ?int $dpi
 * @property ?string $text
 * @property ?array<string, mixed> $ocr
 * @property ?array<string, mixed> $ocr_raw
 */
class DocumentPage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'document_id', 'page_number', 'image_disk', 'image_path', 'width', 'height', 'dpi', 'text', 'ocr', 'ocr_raw', 'char_count', 'confidence', 'ocr_provider'];

    protected function casts(): array
    {
        return ['page_number' => 'integer', 'char_count' => 'integer', 'confidence' => 'float', 'width' => 'integer', 'height' => 'integer', 'dpi' => 'integer', 'ocr' => 'array', 'ocr_raw' => 'array'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
