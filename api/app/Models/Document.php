<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Events\DocumentStatusChanged;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $vendor_id
 * @property ?int $uploaded_by
 * @property ?int $assignee_id
 * @property ?Carbon $assigned_at
 * @property ?int $rejected_by
 * @property ?Carbon $rejected_at
 * @property ?string $rejection_reason
 * @property ?string $declared_type
 * @property ?string $type_source
 * @property ?string $ocr_job_id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property string $source
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $sha256
 * @property ?string $ocr_text
 * @property ?string $ocr_provider
 * @property ?float $ocr_confidence
 * @property ?int $page_count
 * @property ?string $llm_provider
 * @property ?string $llm_model
 * @property ?array<string, mixed> $extraction
 * @property ?float $confidence
 * @property ?string $vendor_name
 * @property ?string $document_number
 * @property ?string $po_number
 * @property ?Carbon $document_date
 * @property ?Carbon $due_date
 * @property ?string $currency
 * @property ?int $subtotal_minor
 * @property ?int $tax_minor
 * @property ?int $total_minor
 * @property ?int $shipping_minor
 * @property ?string $payment_terms
 * @property ?string $schema_version
 * @property ?array<int, array<string, mixed>> $rule_results
 * @property ?int $llm_input_tokens
 * @property ?int $llm_output_tokens
 * @property ?float $llm_cost_usd
 * @property ?array{errors?: list<array<string, string>>, warnings?: list<array<string, string>>, checked_at?: string} $validation
 * @property ?string $last_error
 * @property ?string $email_from
 * @property ?string $email_subject
 * @property ?int $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?int $approved_by
 * @property ?Carbon $approved_at
 * @property ?string $erp_connector
 * @property ?string $erp_ref
 * @property ?Carbon $pushed_at
 * @property Carbon $received_at
 * @property ?array<string, mixed> $vendor_match
 * @property ?Carbon $on_hold_at
 * @property ?string $hold_reason
 * @property ?int $held_by
 * @property ?string $uuid
 * @property ?string $erp_url
 * @property ?string $erp_status
 * @property ?Carbon $erp_status_at
 * @property ?Carbon $paid_at
 * @property bool $touchless
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Document extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['ocr_text'];

    protected static function booted(): void
    {
        // A stable public id: the idempotency key for ERP pushes.
        static::creating(fn (Document $d) => $d->uuid ??= (string) \Illuminate\Support\Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'type' => DocumentType::class,
            'extraction' => 'array',
            'validation' => 'array',
            'confidence' => 'float',
            'ocr_confidence' => 'float',
            'document_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'shipping_minor' => 'integer',
            'rule_results' => 'array',
            'llm_cost_usd' => 'float',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'pushed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'vendor_match' => 'array',
            'on_hold_at' => 'datetime',
            'erp_status_at' => 'datetime',
            'paid_at' => 'datetime',
            'touchless' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('line_no');
    }

    /** @return HasMany<DocumentPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    /** @return HasMany<DocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<DocumentMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(DocumentMatch::class, 'invoice_id')->latest('id');
    }

    /** @return HasOne<DocumentMatch, $this> */
    public function latestMatch(): HasOne
    {
        return $this->hasOne(DocumentMatch::class, 'invoice_id')->latestOfMany();
    }

    public function onHold(): bool
    {
        return $this->on_hold_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** SLA: a document should be reviewed within REVIEW_SLA_HOURS of arriving. */
    public function slaDueAt(): Carbon
    {
        return $this->received_at->copy()->addHours((int) config('extractiq.review.sla_hours', 24));
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function fileContents(): string
    {
        return Storage::disk($this->storage_disk)->get($this->storage_path);
    }

    /**
     * Move along the status machine. Throws when the transition is not allowed,
     * so a bad job can never corrupt the lifecycle.
     */
    public function transitionTo(DocumentStatus $to): DocumentStatus
    {
        $from = $this->status;
        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransition($from, $to);
        }
        if ($from === $to) {
            return $from;
        }
        $this->status = $to;
        $this->save();
        DocumentStatusChanged::dispatch($this, $from);

        return $from;
    }

    public function isPast(DocumentStatus $status): bool
    {
        return $this->status->rank() > $status->rank();
    }
}
