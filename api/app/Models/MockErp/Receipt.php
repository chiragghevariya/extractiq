<?php

namespace App\Models\MockErp;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Mock ERP record (the in-app fake ERP's own data, separate from ExtractIQ's master data).
 *
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property string $po_number
 * @property ?\Illuminate\Support\Carbon $received_date
 * @property list<array<string, mixed>> $lines
 */
class Receipt extends Model
{
    use BelongsToCompany;

    protected $table = 'mock_erp_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'received_date' => 'date:Y-m-d'];
    }
}
