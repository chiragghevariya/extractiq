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
 * @property string $vendor_code
 * @property ?\Illuminate\Support\Carbon $order_date
 * @property string $currency
 * @property string $status
 * @property list<array<string, mixed>> $lines
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'mock_erp_purchase_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'order_date' => 'date:Y-m-d'];
    }
}
