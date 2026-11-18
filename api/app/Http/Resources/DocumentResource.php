<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\Document;
use App\Services\Llm\FieldMap;
use Illuminate\Http\Request;

/** @mixin Document */
class DocumentResource extends JsonApiResource
{
    public function type(): string
    {
        return 'documents';
    }

    public function fields(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source,
            'declared_type' => $this->declared_type,
            'type_source' => $this->type_source,
            'uploaded_by' => $this->uploaded_by ? (string) $this->uploaded_by : null,
            'uploaded_by_name' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'page_count' => $this->page_count,
            'vendor_name' => $this->vendor_name,
            'document_number' => $this->document_number,
            'po_number' => $this->po_number,
            'document_date' => $this->document_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'shipping_minor' => $this->shipping_minor,
            'payment_terms' => $this->payment_terms,
            'total_minor' => $this->total_minor,
            'confidence' => $this->confidence,
            'field_confidence' => $this->fieldMeta('confidence'),
            'field_sources' => $this->fieldMeta('source_words'),
            'schema_version' => $this->schema_version,
            'rule_results' => $this->rule_results ?? [],
            'llm_input_tokens' => $this->llm_input_tokens,
            'llm_output_tokens' => $this->llm_output_tokens,
            'llm_cost_usd' => $this->llm_cost_usd,
            'hints_used' => data_get($this->extraction, 'hints_used', []),
            'extracted' => $this->when($request->routeIs('documents.show'), fn () => $this->maskedForViewer($request, data_get($this->extraction, 'data'))),
            'ocr_provider' => $this->ocr_provider,
            'ocr_confidence' => $this->ocr_confidence,
            'llm_provider' => $this->llm_provider,
            'llm_model' => $this->llm_model,
            'validation' => $this->validation ?? ['errors' => [], 'warnings' => []],
            'last_error' => $this->last_error,
            'email_from' => $this->email_from,
            'email_subject' => $this->email_subject,
            'erp_connector' => $this->erp_connector,
            'erp_ref' => $this->erp_ref,
            'editable' => $this->status->isEditable(),
            'assignee' => $this->assignee_id ? ['id' => (string) $this->assignee_id, 'name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name)] : null,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'sla_due_at' => $this->slaDueAt()->toIso8601String(),
            'vendor_match' => $this->vendor_match ? [
                'method' => $this->vendor_match['method'] ?? 'none',
                'confidence' => $this->vendor_match['confidence'] ?? null,
                'candidates' => $this->vendor_match['candidates'] ?? [],
                'reason' => $this->vendor_match['reason'] ?? null,
            ] : null,
            'on_hold' => $this->on_hold_at !== null,
            'uuid' => $this->uuid,
            'erp_url' => $this->erp_url,
            'erp_status' => $this->erp_status,
            'erp_status_at' => $this->erp_status_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'touchless' => (bool) $this->touchless,
            'on_hold_at' => $this->on_hold_at?->toIso8601String(),
            'hold_reason' => $this->hold_reason,
            'received_at' => $this->received_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'pushed_at' => $this->pushed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen($request->routeIs('documents.show'), fn () => [
                'ocr_text' => $this->ocr_text,
            ]),
        ];
    }

    /**
     * Per-field extraction meta keyed by the form's field names (vendor_name, document_number, total,
     * lines.1 …) so the UI can show confidence and highlight source words.
     */
    private function fieldMeta(string $key): ?array
    {
        $fields = data_get($this->extraction, 'fields');
        if (! $fields) {
            return null;
        }
        $out = [];
        foreach (FieldMap::for($this->type->value === 'unknown' ? 'invoice' : $this->type->value) as $column => $path) {
            if (isset($fields[$path]) && ($fields[$path]['has_value'] ?? true)) {
                $out[$column] = $fields[$path][$key] ?? null;
            }
        }
        $lineConf = [];
        foreach ($fields as $path => $meta) {
            if (preg_match('/^line_items\.(\d+)$/', $path, $m)) {
                $out['lines.'.($m[1] + 1)] = $meta[$key] ?? null;
                $lineConf[] = (float) ($meta['confidence'] ?? 0);
            }
        }
        if ($key === 'confidence' && $lineConf) {
            $out['lines'] = min($lineConf);
        }

        return $out;
    }

    public function relationships(Request $request): array
    {
        $rel = [
            'vendor' => ['data' => self::identifier('vendors', $this->vendor_id)],
        ];
        if ($this->relationLoaded('lines')) {
            $rel['lines'] = ['data' => $this->lines->map(fn ($l) => self::identifier('document-lines', $l->id))->all()];
        }
        if ($this->relationLoaded('latestMatch')) {
            $rel['latest_match'] = ['data' => self::identifier('matches', $this->latestMatch?->id)];
        }

        return $rel;
    }

    public function with(Request $request): array
    {
        if (! $request->routeIs('documents.show', 'documents.update', 'documents.extraction', 'documents.approve', 'documents.reject', 'documents.reopen', 'documents.reassign', 'documents.override-match')) {
            return [];
        }
        $included = [];
        if ($this->vendor) {
            $included[] = (new VendorResource($this->vendor))->resolve($request);
        }
        foreach ($this->lines as $line) {
            $included[] = (new DocumentLineResource($line))->resolve($request);
        }
        if ($this->relationLoaded('latestMatch') && $this->latestMatch) {
            $included[] = (new MatchResource($this->latestMatch))->resolve($request);
        }

        return ['included' => $included];
    }

    /** Viewers see that bank details exist, never the account itself. */
    private function maskedForViewer(Request $request, mixed $data): mixed
    {
        if (! is_array($data) || ! isset($data['bank']) || $request->user()?->role !== UserRole::Viewer) {
            return $data;
        }
        foreach (['account', 'swift'] as $key) {
            $value = data_get($data, "bank.$key.value");
            if (is_string($value) && $value !== '') {
                data_set($data, "bank.$key.value", '•••• '.substr((string) preg_replace('/[^A-Z0-9]/i', '', $value), -4));
            }
        }

        return $data;
    }
}
