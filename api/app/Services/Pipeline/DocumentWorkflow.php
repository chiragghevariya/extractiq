<?php

namespace App\Services\Pipeline;

use App\Services\Settings\ApprovalLimits;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\ExtractFields;
use App\Jobs\MatchDocument;
use App\Jobs\ProcessPages;
use App\Jobs\PushToErp;
use App\Jobs\RunOcr;
use App\Jobs\ValidateDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Llm\FieldMap;
use App\Services\Validation\Rules\FieldConfidence;
use App\Services\Matching\MasterData;
use App\Services\Matching\MatchActions;
use App\Services\Matching\Rematcher;
use App\Services\Matching\VendorResolver;
use App\Services\Vendors\HintLearner;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Human-driven actions on a document. Each writes a document_events row with the acting user. */
class DocumentWorkflow
{
    public const EDITABLE = ['type', 'vendor_name', 'vendor_id', 'document_number', 'po_number', 'document_date', 'due_date', 'payment_terms', 'currency', 'subtotal_minor', 'tax_minor', 'shipping_minor', 'total_minor'];

    public function __construct(
        private readonly EventRecorder $events,
        private readonly DocumentValidator $validator,
        private readonly ExtractionApplier $applier,
        private readonly HintLearner $hints,
    ) {}

    /** @deprecated use updateExtraction() — kept for callers that send no accepted fields. */
    public function update(Document $doc, array $fields, ?array $lines, ?User $user): Document
    {
        return $this->updateExtraction($doc, $fields, $lines, [], $user);
    }

    /**
     * Reviewer corrections (PATCH /documents/{id}/extraction).
     *  - writes a "review" event with the field-level diff: before/after per field and per line, who, when
     *  - edited and explicitly accepted fields become human-confirmed (confidence 1.0)
     *  - corrections feed vendor memory (HintLearner)
     *  - re-validates synchronously so the UI gets fresh rule results immediately
     *
     * @param  list<string>  $accepted  form keys the reviewer accepted as-is (e.g. ["document_date", "lines.2"])
     */
    public function updateExtraction(Document $doc, array $fields, ?array $lines, array $accepted, ?User $user): Document
    {
        if (! $doc->status->isEditable()) {
            throw ValidationException::withMessages(['status' => "A {$doc->status->label()} document can no longer be edited."]);
        }

        return DB::transaction(function () use ($doc, $fields, $lines, $accepted, $user) {
            $doc->loadMissing('lines');
            $diff = ['fields' => [], 'lines' => [], 'accepted' => []];

            foreach (array_intersect_key($fields, array_flip(self::EDITABLE)) as $key => $value) {
                $before = $this->plain($doc->getAttribute($key));
                if ((string) $before !== (string) $value) {
                    $diff['fields'][$key] = ['before' => $before, 'after' => $value];
                }
                $doc->setAttribute($key, $value);
            }
            if (isset($diff['fields']['type'])) {
                $doc->type_source = 'manual';
            }
            if (array_key_exists('vendor_id', $fields) && $fields['vendor_id']) {
                $doc->vendor_match = ['method' => 'manual', 'confidence' => 1.0, 'candidates' => $doc->vendor_match['candidates'] ?? [], 'extracted_name' => $doc->vendor_name];
            } elseif (isset($diff['fields']['vendor_name'])) {
                $doc->vendor_match = null; // the name changed: a previous manual pick no longer applies
                app(VendorResolver::class)->apply($doc);
            }

            if ($lines !== null) {
                $diff['lines'] = $this->lineDiff($doc, $lines);
                $this->applier->replaceLines($doc, $lines);
                $doc->unsetRelation('lines');
            }

            // Human confirmation: edited + accepted fields are now certain.
            $confirmed = array_unique(array_merge(
                array_map(fn ($k) => str_ends_with($k, '_minor') ? substr($k, 0, -6) : $k, array_keys($diff['fields'])),
                $accepted,
            ));
            $diff['accepted'] = array_values(array_diff($accepted, array_keys($diff['fields'])));
            $doc->extraction = $this->confirm($doc, $confirmed, $lines !== null);

            // Vendor memory: learn where each corrected value appears on this vendor's documents.
            $learned = [];
            foreach ($diff['fields'] as $column => $change) {
                $key = str_ends_with($column, '_minor') ? substr($column, 0, -6) : $column;
                $path = FieldMap::for($doc->type->value)[$key] ?? null;
                $value = $change['after'];
                if ($value !== null && str_ends_with($column, '_minor')) {
                    $value = number_format($value / (10 ** Money::exponent($doc->currency)), Money::exponent($doc->currency), '.', '');
                }
                if ($path && ($hint = $this->hints->learn($doc, $path, $value, $user))) {
                    $learned[] = $hint->hint;
                }
            }

            $doc->reviewed_by = $user?->id;
            $doc->reviewed_at = now();
            $doc->save();

            $from = $doc->status;
            $result = $this->validator->validate($doc);
            $doc->validation = ['errors' => $result['errors'], 'warnings' => $result['warnings'], 'checked_at' => now()->toIso8601String()];
            $doc->rule_results = $result['results'];
            $doc->transitionTo($result['status']);
            $doc->save();

            $this->events->record($doc, 'review', 'succeeded', $from->value, $doc->status->value,
                meta: array_filter([
                    'diff' => $diff,
                    'changes' => $diff['fields'], // kept for older clients
                    'hints_learned' => $learned ?: null,
                    'errors' => count($result['errors']),
                ], fn ($v) => $v !== null), userId: $user?->id);

            return $doc->refresh();
        });
    }

    /** Mark fields (form keys) as human-confirmed in the stored extraction. */
    private function confirm(Document $doc, array $keys, bool $linesReviewed): ?array
    {
        $extraction = $doc->extraction;
        if (! $extraction || empty($extraction['fields'])) {
            return $extraction;
        }
        $map = FieldMap::for($doc->type->value === 'unknown' ? 'invoice' : $doc->type->value);
        foreach ($keys as $key) {
            $path = preg_match('/^lines\.(\d+)$/', $key, $m) ? 'line_items.'.($m[1] - 1) : ($map[$key] ?? null);
            if ($path && isset($extraction['fields'][$path])) {
                $extraction['fields'][$path]['confidence'] = 1.0;
                $extraction['fields'][$path]['human_verified'] = true;
            }
        }
        if ($linesReviewed) {
            // The reviewer saved the grid: its lines are what they confirmed.
            foreach (array_keys($extraction['fields']) as $path) {
                if (str_starts_with($path, 'line_items.')) {
                    unset($extraction['fields'][$path]);
                }
            }
            $extraction['fields']['line_items.reviewed'] = ['confidence' => 1.0, 'human_verified' => true, 'has_value' => true, 'source_words' => [], 'source_lines' => []];
        }

        return $extraction;
    }

    /** Per-line before/after: added, removed and changed lines (matched by position). */
    private function lineDiff(Document $doc, array $lines): array
    {
        $cols = ['sku', 'description', 'quantity', 'uom', 'unit_price_minor', 'tax_rate', 'amount_minor'];
        $old = $doc->lines->values();
        $out = [];
        $count = max($old->count(), count($lines));
        for ($i = 0; $i < $count; $i++) {
            $before = isset($old[$i]) ? array_map(fn ($c) => $this->plain($old[$i]->{$c}), array_combine($cols, $cols)) : null;
            $after = isset($lines[$i]) ? array_map(fn ($c) => $lines[$i][$c] ?? null, array_combine($cols, $cols)) : null;
            if ($before === null) {
                $out[] = ['line' => $i + 1, 'change' => 'added', 'after' => $after];
            } elseif ($after === null) {
                $out[] = ['line' => $i + 1, 'change' => 'removed', 'before' => $before];
            } else {
                $changed = [];
                foreach ($cols as $c) {
                    $b = $before[$c];
                    $a = $after[$c];
                    $equal = is_numeric($b) && is_numeric($a) ? abs((float) $b - (float) $a) < 0.0005 : (string) $b === (string) $a;
                    if (! $equal) {
                        $changed[$c] = ['before' => $b, 'after' => $a];
                    }
                }
                if ($changed) {
                    $out[] = ['line' => $i + 1, 'change' => 'changed', 'fields' => $changed];
                }
            }
        }

        return $out;
    }

    private function plain(mixed $v): mixed
    {
        return $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v);
    }

    /** Refuse a document (with a reason). Terminal until reopened. */
    public function reject(Document $doc, User $user, string $reason): Document
    {
        if (! in_array($doc->status, [DocumentStatus::NeedsReview, DocumentStatus::Validated], true)) {
            throw ValidationException::withMessages(['status' => "Only documents awaiting review can be rejected (status: {$doc->status->label()})."]);
        }
        $from = $doc->status;
        $doc->fill(['rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
        $doc->transitionTo(DocumentStatus::Rejected);
        $this->events->record($doc, 'reject', 'succeeded', $from->value, DocumentStatus::Rejected->value,
            meta: ['reason' => $reason, 'diff' => ['fields' => ['status' => ['before' => $from->value, 'after' => 'rejected']]]], userId: $user->id);

        return $doc;
    }

    public function reopen(Document $doc, User $user): Document
    {
        if ($doc->status !== DocumentStatus::Rejected) {
            throw ValidationException::withMessages(['status' => 'Only rejected documents can be reopened.']);
        }
        $doc->fill(['rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null]);
        $doc->transitionTo(DocumentStatus::NeedsReview);
        $this->events->record($doc, 'reopen', 'succeeded', 'rejected', 'needs_review', userId: $user->id);

        return $doc;
    }

    /** Assign (or unassign with null) the reviewer responsible for a document. */
    public function reassign(Document $doc, User $actor, ?User $assignee): Document
    {
        $before = $doc->assignee;
        if ($before?->id === $assignee?->id) {
            return $doc;
        }
        $doc->forceFill(['assignee_id' => $assignee?->id, 'assigned_at' => $assignee ? now() : null])->save();
        $this->events->record($doc, 'reassign', 'succeeded', meta: ['diff' => ['fields' => ['assignee' => [
            'before' => $before ? ['id' => (string) $before->id, 'name' => $before->name] : null,
            'after' => $assignee ? ['id' => (string) $assignee->id, 'name' => $assignee->name] : null,
        ]]]], userId: $actor->id);

        return $doc->setRelation('assignee', $assignee);
    }

    /**
     * Approve. Optional pending edits (fields/lines/accepted) are applied first — that's the
     * Ctrl+Enter path, so a reviewer never approves a different version than they see.
     */
    public function approve(Document $doc, ?User $user, ?array $pending = null): Document
    {
        if ($pending && $user && (! empty($pending['fields']) || isset($pending['lines']) || ! empty($pending['accepted']))) {
            $doc = $this->updateExtraction($doc, $pending['fields'] ?? [], $pending['lines'] ?? null, $pending['accepted'] ?? [], $user);
        }
        if (! in_array($doc->status, [DocumentStatus::Validated, DocumentStatus::NeedsReview], true)) {
            throw ValidationException::withMessages(['status' => "Only validated or reviewed documents can be approved (status: {$doc->status->label()})."]);
        }
        if (! empty($doc->validation['errors'])) {
            throw ValidationException::withMessages(['validation' => 'Fix the failed checks before approving.']);
        }
        if ($user) {
            ApprovalLimits::for($doc->company_id)->authorize($user, $doc);
        }

        $from = $doc->status;
        $doc->fill(['approved_by' => $user?->id, 'approved_at' => now()]);
        $doc->transitionTo(DocumentStatus::Approved);
        $unconfirmed = array_keys(FieldConfidence::lowFields($doc->extraction['fields'] ?? [], (float) config('extractiq.review_confidence_threshold')));
        $this->events->record($doc, 'approve', 'succeeded', $from->value, DocumentStatus::Approved->value,
            meta: array_filter([
                'auto' => $user === null,
                'touchless' => $user === null && $doc->touchless ? true : null,
                'diff' => ['fields' => ['status' => ['before' => $from->value, 'after' => 'approved']]],
                // Low-confidence fields approved without being explicitly accepted — visible in the audit trail.
                'approved_unconfirmed' => $unconfirmed ?: null,
            ], fn ($v) => $v !== null), userId: $user?->id);

        if ($doc->type->flowsToErp()) {
            MatchDocument::dispatch($doc->id);
        } elseif (in_array($doc->type, [DocumentType::PurchaseOrder, DocumentType::GoodsReceipt], true)) {
            $this->rematchWaitingInvoices($doc);
        }

        return $doc;
    }

    /** A newly approved PO/GRN becomes master data and may resolve invoices stuck in match_exception. */
    public function rematchWaitingInvoices(Document $reference): int
    {
        $po = app(MasterData::class)->syncDocument($reference);

        return $po ? app(Rematcher::class)->forPurchaseOrders($reference->company_id, [$po->id]) : 0;
    }

    /** Accept every open variance on an invoice (kept for the older override-match endpoint). */
    public function overrideMatch(Document $doc, User $user, string $reason): Document
    {
        app(MatchActions::class)->acceptAll($doc, $user, $reason);

        return $doc->refresh();
    }

    /** Re-queue whatever step the document is waiting on. A failed document goes back to received. */
    public function retry(Document $doc, User $user): string
    {
        if ($doc->status === DocumentStatus::Failed) {
            $doc->forceFill(['last_error' => null]);
            $doc->transitionTo(DocumentStatus::Received);
        }

        $job = match ($doc->status) {
            DocumentStatus::Received => $doc->pages()->whereNotNull('image_path')->exists() ? RunOcr::class : ProcessPages::class,
            DocumentStatus::OcrDone => ExtractFields::class,
            DocumentStatus::Extracted => ValidateDocument::class,
            DocumentStatus::Approved, DocumentStatus::MatchException => MatchDocument::class,
            DocumentStatus::Matched, DocumentStatus::PushFailed => PushToErp::class,
            default => null,
        };
        if (! $job) {
            throw ValidationException::withMessages(['status' => "Nothing to retry for a {$doc->status->label()} document."]);
        }
        $job::dispatch($doc->id);
        $step = (new $job($doc->id))->step();
        $this->events->record($doc, 'retry', 'info', $doc->status->value, null, meta: ['step' => $step], userId: $user->id);

        return $step;
    }
}
