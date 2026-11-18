<?php

namespace App\Models\MockErp;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Mock ERP record (the in-app fake ERP's own data, separate from ExtractIQ's master data).
 *
 * @property int $id
 * @property int $company_id
 * @property string $ref
 * @property string $idempotency_key
 * @property ?string $vendor_code
 * @property string $vendor_name
 * @property string $bill_number
 * @property ?string $po_number
 * @property ?\Illuminate\Support\Carbon $bill_date
 * @property ?\Illuminate\Support\Carbon $due_date
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property list<array<string, mixed>> $lines
 * @property ?array<string, string> $fields
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $posted_at
 * @property ?\Illuminate\Support\Carbon $paid_at
 * @property ?string $attachment_name
 * @property ?string $attachment_path
 * @property ?string $attachment_disk
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Bill extends Model
{
    use BelongsToCompany;

    protected $table = 'mock_erp_bills';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'fields' => 'array', 'bill_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'paid_at' => 'datetime', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }
}
