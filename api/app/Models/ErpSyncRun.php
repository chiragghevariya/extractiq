<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $connector
 * @property string $trigger
 * @property string $status
 * @property ?array<string, mixed> $counts
 * @property ?list<string> $errors
 * @property ?int $duration_ms
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 */
class ErpSyncRun extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'connector', 'trigger', 'status', 'counts', 'errors', 'duration_ms', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'errors' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
