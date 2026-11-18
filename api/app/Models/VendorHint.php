<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_id
 * @property string $field
 * @property ?string $label
 * @property string $hint
 * @property ?string $example_value
 * @property int $times_used
 * @property ?Carbon $last_used_at
 * @property ?int $source_document_id
 */
class VendorHint extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vendor_id', 'field', 'label', 'hint', 'example_value', 'source_document_id', 'created_by', 'times_used', 'last_used_at'];

    protected function casts(): array
    {
        return ['times_used' => 'integer', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
