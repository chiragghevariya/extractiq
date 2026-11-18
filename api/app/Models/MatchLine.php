<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_match_id
 * @property ?int $invoice_line_id
 * @property ?int $po_line_id
 * @property int $line_no
 * @property ?string $sku
 * @property string $description
 * @property string $method
 * @property ?float $similarity
 * @property string $result
 * @property list<string> $issues
 * @property float $invoiced_qty
 * @property ?float $ordered_qty
 * @property ?float $received_qty
 * @property float $previously_invoiced_qty
 * @property ?int $invoice_price_minor
 * @property ?int $po_price_minor
 * @property ?int $invoice_amount_minor
 * @property int $disputed_minor
 * @property ?int $accepted_by
 * @property ?Carbon $accepted_at
 * @property ?string $accept_reason
 * @property-read ?PoLine $poLine
 * @property-read DocumentMatch $match
 */
class MatchLine extends Model
{
    use BelongsToCompany;

    public const RESULTS = ['matched', 'price_variance', 'qty_variance', 'not_received', 'not_on_po'];

    protected $fillable = [
        'company_id', 'document_match_id', 'invoice_line_id', 'po_line_id', 'line_no', 'sku', 'description', 'method', 'similarity',
        'result', 'issues', 'invoiced_qty', 'ordered_qty', 'received_qty', 'previously_invoiced_qty',
        'invoice_price_minor', 'po_price_minor', 'invoice_amount_minor', 'disputed_minor', 'accepted_by', 'accepted_at', 'accept_reason',
    ];

    protected function casts(): array
    {
        return [
            'issues' => 'array', 'similarity' => 'float', 'invoiced_qty' => 'float', 'ordered_qty' => 'float', 'received_qty' => 'float',
            'previously_invoiced_qty' => 'float', 'invoice_price_minor' => 'integer', 'po_price_minor' => 'integer',
            'invoice_amount_minor' => 'integer', 'disputed_minor' => 'integer', 'accepted_at' => 'datetime', 'line_no' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(DocumentMatch::class, 'document_match_id');
    }

    /** @return BelongsTo<PoLine, $this> */
    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }

    public function isOk(): bool
    {
        return $this->result === 'matched' || $this->accepted_at !== null;
    }
}
