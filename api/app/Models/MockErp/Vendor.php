<?php

namespace App\Models\MockErp;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Mock ERP record (the in-app fake ERP's own data, separate from ExtractIQ's master data).
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property ?string $tax_id
 * @property ?string $email
 * @property string $currency
 * @property ?list<string> $aliases
 */
class Vendor extends Model
{
    use BelongsToCompany;

    protected $table = 'mock_erp_vendors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }
}
