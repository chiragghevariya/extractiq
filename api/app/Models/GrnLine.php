<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property ?int $po_line_id
 * @property int $line_no
 * @property ?string $sku
 * @property string $description
 * @property float $quantity
 * @property ?string $uom
 */
class GrnLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'goods_receipt_id', 'po_line_id', 'line_no', 'sku', 'description', 'quantity', 'uom'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'line_no' => 'integer'];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<PoLine, $this> */
    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }
}
