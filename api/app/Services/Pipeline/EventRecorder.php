<?php

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\DocumentEvent;
use Throwable;

/** Writes the document_events audit trail. Every pipeline step and human action goes through here. */
class EventRecorder
{
    public function record(
        Document $document,
        string $step,
        string $status,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?int $durationMs = null,
        int $attempt = 1,
        ?string $error = null,
        array $meta = [],
        ?int $userId = null,
    ): DocumentEvent {
        return DocumentEvent::withoutGlobalScopes()->create([
            'company_id' => $document->company_id,
            'document_id' => $document->id,
            'user_id' => $userId,
            'step' => $step,
            'status' => $status,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
            'error' => $error ? mb_substr($error, 0, 4000) : null,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Time a step, record success or failure, and rethrow failures so the
     * queue's retry/backoff kicks in.
     *
     * @template T
     *
     * @param  callable(): T  $callback  may return ['to' => DocumentStatus, 'meta' => [...]]
     */
    public function step(Document $document, string $step, int $attempt, callable $callback): mixed
    {
        $start = hrtime(true);
        $from = $document->status->value;
        try {
            $result = $callback();
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->record(
                $document, $step, 'succeeded', $from, $document->fresh()?->status->value,
                $ms, $attempt, null, is_array($result) ? ($result['meta'] ?? []) : [],
            );

            return $result;
        } catch (Throwable $e) {
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->record($document, $step, 'failed', $from, null, $ms, $attempt, $e->getMessage());
            throw $e;
        }
    }
}
