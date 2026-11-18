<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $line_no
 * @property ?string $sku
 * @property string $description
 * @property float $quantity
 * @property ?int $unit_price_minor
 * @property ?int $amount_minor
 * @property ?string $uom
 * @property ?float $tax_rate
 */
class DocumentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'document_id', 'line_no', 'sku', 'description', 'quantity', 'uom', 'unit_price_minor', 'tax_rate', 'amount_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'line_no' => 'integer', 'tax_rate' => 'float'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
