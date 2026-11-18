<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Erp\ErpException;
use App\Services\Llm\LlmException;
use App\Services\Ocr\OcrException;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Base for every pipeline step:
 *  - idempotent: does nothing unless the document is in an expected input status
 *  - unique per document until it starts, so double-dispatch can't run a step twice in parallel
 *  - retried with exponential backoff; non-retryable provider errors fail fast
 *  - every attempt is written to document_events with duration_ms and error
 */
abstract class PipelineJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 180;

    /** @var list<callable> follow-ups run only after the step's event is recorded */
    private array $afterSuccess = [];

    public function __construct(public int $documentId)
    {
        $this->tries = (int) config('extractiq.pipeline.tries', 3);
        $this->onQueue(config('extractiq.pipeline.queue', 'pipeline'));
    }

    /** Step name for the audit trail. */
    abstract public function step(): string;

    /** @return list<DocumentStatus> statuses this step accepts as input */
    abstract protected function accepts(): array;

    /** Do the work. Return ['meta' => [...]] to enrich the event. */
    abstract protected function process(Document $document): ?array;

    public function backoff(): array
    {
        return config('extractiq.pipeline.backoff', [10, 60, 300]);
    }

    public function uniqueId(): string
    {
        return static::class.':'.$this->documentId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(EventRecorder $events): void
    {
        $document = Document::withoutGlobalScopes()->with('company')->find($this->documentId);
        if (! $document) {
            return;
        }

        if (! in_array($document->status, $this->accepts(), true)) {
            // Already processed (or moved on by a human) — idempotent no-op.
            $events->record($document, $this->step(), 'skipped', $document->status->value, null, attempt: $this->attempts(),
                meta: ['reason' => 'status_not_eligible', 'status' => $document->status->value]);

            return;
        }

        if ($reason = $this->blockedBy($document)) {
            $events->record($document, $this->step(), 'skipped', $document->status->value, null, attempt: $this->attempts(), meta: ['reason' => $reason]);

            return;
        }

        try {
            $this->afterSuccess = [];
            $events->step($document, $this->step(), $this->attempts(), fn () => $this->process($document));
            if ($document->last_error) {
                $document->forceFill(['last_error' => null])->saveQuietly();
            }
            foreach ($this->drainAfterSuccess() as $callback) {
                $callback();
            }
        } catch (LlmException|ErpException|OcrException $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);
        if (! $document) {
            return;
        }
        $document->forceFill(['last_error' => mb_substr((string) $e?->getMessage(), 0, 2000)])->saveQuietly();
        $this->onFinalFailure($document, $e);
    }

    /** A reason this step must not run right now (e.g. the document is on hold), or null. */
    protected function blockedBy(Document $document): ?string
    {
        return null;
    }

    /** Queue the next pipeline step (or any follow-up) once this step has succeeded and been logged. */
    protected function then(callable $callback): void
    {
        $this->afterSuccess[] = $callback;
    }

    /** @return list<callable> */
    private function drainAfterSuccess(): array
    {
        [$callbacks, $this->afterSuccess] = [$this->afterSuccess, []];

        return $callbacks;
    }

    protected function onFinalFailure(Document $document, ?Throwable $e): void {}
}
