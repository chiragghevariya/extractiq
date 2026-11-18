<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property ?string $sku
 * @property string $description
 * @property float $quantity
 * @property ?string $uom
 * @property ?int $unit_price_minor
 * @property ?int $amount_minor
 * @property ?string $erp_ref
 * @property-read PurchaseOrder $purchaseOrder
 */
class PoLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_order_id', 'line_no', 'sku', 'description', 'quantity', 'uom', 'unit_price_minor', 'amount_minor', 'erp_ref'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'line_no' => 'integer'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<GrnLine, $this> */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(GrnLine::class);
    }
}
