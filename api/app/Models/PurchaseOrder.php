<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Master-data purchase order (from CSV, the ERP, or an approved PO document).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $vendor_id
 * @property string $po_number
 * @property string $number_key
 * @property ?Carbon $order_date
 * @property ?Carbon $expected_date
 * @property ?string $currency
 * @property ?int $subtotal_minor
 * @property ?int $tax_minor
 * @property ?int $total_minor
 * @property string $status
 * @property string $source
 * @property ?int $source_document_id
 * @property ?string $erp_ref
 * @property-read ?Vendor $vendor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PoLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GoodsReceipt> $receipts
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['csv', 'erp', 'document', 'manual'];

    protected $fillable = ['company_id', 'vendor_id', 'po_number', 'order_date', 'expected_date', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'status', 'source', 'source_document_id', 'erp_ref'];

    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (PurchaseOrder $po) => $po->number_key = self::key($po->po_number));
    }

    /** "po 1001" / "PO-1001" / "po#1001" all become "PO1001". */
    public static function key(?string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<PoLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PoLine::class)->orderBy('line_no');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_date');
    }

    /** @return BelongsTo<Document, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}
