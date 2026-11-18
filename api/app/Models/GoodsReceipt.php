<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $purchase_order_id
 * @property ?int $vendor_id
 * @property string $grn_number
 * @property string $number_key
 * @property ?Carbon $received_date
 * @property string $source
 * @property ?int $source_document_id
 * @property ?string $erp_ref
 * @property-read ?PurchaseOrder $purchaseOrder
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GrnLine> $lines
 */
class GoodsReceipt extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_order_id', 'vendor_id', 'grn_number', 'received_date', 'source', 'source_document_id', 'erp_ref'];

    protected function casts(): array
    {
        return ['received_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::saving(fn (GoodsReceipt $g) => $g->number_key = PurchaseOrder::key($g->grn_number));
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<GrnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GrnLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Document, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}
