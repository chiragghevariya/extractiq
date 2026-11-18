<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One matching run for an invoice. Older runs stay as history (is_current = false).
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property ?int $purchase_order_id
 * @property string $kind
 * @property string $result
 * @property bool $is_current
 * @property ?string $po_method
 * @property ?float $po_confidence
 * @property list<array<string, mixed>> $header_issues
 * @property list<array<string, mixed>> $discrepancies
 * @property array<string, int> $totals
 * @property list<int> $goods_receipt_ids
 * @property array<string, mixed> $tolerances
 * @property ?int $accepted_by
 * @property ?Carbon $accepted_at
 * @property ?string $accept_reason
 * @property ?int $credit_note_requested_by
 * @property ?Carbon $credit_note_requested_at
 * @property ?int $credit_note_amount_minor
 * @property ?string $credit_note_reason
 * @property ?Carbon $created_at
 * @property-read Document $invoice
 * @property-read ?PurchaseOrder $purchaseOrder
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MatchLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GoodsReceipt> $receipts
 */
class DocumentMatch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'invoice_id', 'purchase_order_id', 'kind', 'result', 'is_current', 'po_method', 'po_confidence',
        'header_issues', 'discrepancies', 'totals', 'goods_receipt_ids', 'tolerances',
        'accepted_by', 'accepted_at', 'accept_reason',
        'credit_note_requested_by', 'credit_note_requested_at', 'credit_note_amount_minor', 'credit_note_reason',
    ];

    protected function casts(): array
    {
        return [
            'header_issues' => 'array', 'discrepancies' => 'array', 'totals' => 'array', 'goods_receipt_ids' => 'array', 'tolerances' => 'array',
            'is_current' => 'boolean', 'po_confidence' => 'float', 'accepted_at' => 'datetime', 'credit_note_requested_at' => 'datetime',
            'credit_note_amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_id');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<MatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(MatchLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creditNoteRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_note_requested_by');
    }

    /** Receipts are stored as an id list (a snapshot of what the run saw); load them as a relation for the API. */
    public function loadReceipts(): static
    {
        return $this->setRelation('receipts', GoodsReceipt::withoutGlobalScopes()->whereIn('id', $this->goods_receipt_ids ?? [])->orderBy('received_date')->get());
    }

    /** Everything the matching view needs. */
    public function loadForView(): static
    {
        $this->loadMissing('lines.poLine', 'purchaseOrder.vendor', 'invoice', 'acceptedBy:id,name', 'creditNoteRequestedBy:id,name');

        return $this->loadReceipts();
    }

    public function isOk(): bool
    {
        return in_array($this->result, ['matched', 'accepted'], true);
    }
}
