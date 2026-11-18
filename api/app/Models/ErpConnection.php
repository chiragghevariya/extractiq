<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A company's link to one ERP connector: its health, the field mapping used when pushing bills,
 * and (QuickBooks) the OAuth tokens obtained at runtime. Configured secrets (passwords, client
 * secrets) stay in .env; only tokens the ERP hands out are stored, encrypted.
 *
 * @property int $id
 * @property int $company_id
 * @property string $connector
 * @property bool $is_active
 * @property string $status
 * @property ?string $status_message
 * @property ?Carbon $tested_at
 * @property ?array<string, mixed> $mapping
 * @property ?array<string, mixed> $settings
 * @property ?array<string, mixed> $credentials
 * @property ?Carbon $last_sync_at
 */
class ErpConnection extends Model
{
    use BelongsToCompany;

    public const CONNECTORS = ['mock', 'odoo', 'quickbooks', 'webhook'];

    protected $fillable = ['company_id', 'connector', 'is_active', 'status', 'status_message', 'tested_at', 'mapping', 'settings', 'credentials', 'last_sync_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'tested_at' => 'datetime', 'last_sync_at' => 'datetime',
            'mapping' => 'array', 'settings' => 'array', 'credentials' => 'encrypted:array',
        ];
    }

    public static function for(int $companyId, string $connector): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'connector' => $connector], ['is_active' => false, 'status' => 'unknown']);
    }

    /** The connector this company pushes to: the active row, else ERP_DRIVER. */
    public static function activeFor(int $companyId): self
    {
        return self::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->first()
            ?? self::for($companyId, (string) config('extractiq.erp.default'));
    }
}
