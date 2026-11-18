<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property ?int $user_id
 * @property string $step
 * @property string $status
 * @property ?array<string, mixed> $meta
 * @property ?int $duration_ms
 * @property Carbon $created_at
 */
class DocumentEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'document_id', 'user_id', 'step', 'status', 'from_status', 'to_status', 'duration_ms', 'attempt', 'error', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'duration_ms' => 'integer', 'attempt' => 'integer'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
