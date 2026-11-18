<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property string $connector
 * @property string $idempotency_key
 * @property string $status
 * @property ?string $external_ref
 * @property ?string $error
 * @property int $attempt
 * @property ?int $http_status
 * @property ?int $duration_ms
 * @property ?bool $retryable
 * @property ?array<string, mixed> $request
 * @property ?array<string, mixed> $response
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class ErpPush extends Model
{
    use BelongsToCompany;

    /** succeeded | failed (will be retried) | dead (gave up: dead-lettered, needs a person) */
    protected $fillable = ['company_id', 'document_id', 'connector', 'idempotency_key', 'status', 'attempt', 'external_ref', 'request', 'response', 'error', 'http_status', 'duration_ms', 'retryable'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'retryable' => 'boolean'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
