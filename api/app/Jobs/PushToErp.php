<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\ErpConnection;
use App\Models\ErpPush;
use App\Services\Erp\ErpBill;
use App\Services\Erp\ErpException;
use App\Services\Erp\ErpManager;
use App\Services\Erp\ErpMapping;
use App\Services\Pipeline\EventRecorder;
use Throwable;

/**
 * Sends an approved, matched (or variance-accepted) invoice to the company's ERP as a vendor bill.
 *
 *  - Idempotency key = the document uuid; a key that already succeeded is never sent again, and
 *    every connector also deduplicates on its side.
 *  - Every attempt is logged in erp_pushes with the request, the ERP's response and HTTP status.
 *  - Retried with backoff (ERP_PUSH_TRIES = 5, ERP_PUSH_BACKOFF); after the last attempt, or at once
 *    for errors that won't go away by retrying, the push is dead-lettered: status push_failed and the
 *    ERP's message shown in the UI until someone retries.
 */
class PushToErp extends PipelineJob
{
    public function __construct(int $documentId)
    {
        parent::__construct($documentId);
        $this->tries = (int) config('extractiq.erp.push.tries', 5);
    }

    public function step(): string
    {
        return 'push';
    }

    public function backoff(): array
    {
        return config('extractiq.erp.push.backoff', [30, 120, 600, 1800]);
    }

    protected function accepts(): array
    {
        return [DocumentStatus::Matched, DocumentStatus::PushFailed];
    }

    protected function blockedBy(Document $document): ?string
    {
        if ($document->onHold()) {
            return 'on_hold';
        }
        $match = $document->latestMatch;

        return $match && ! $match->isOk() ? 'match_not_resolved' : null;
    }

    protected function process(Document $document): ?array
    {
        $connection = ErpConnection::activeFor($document->company_id);
        $connector = app(ErpManager::class)->forCompany($document->company_id, $connection->connector);
        $bill = ErpBill::fromDocument($document, ErpMapping::for($connection));

        $previous = ErpPush::withoutGlobalScopes()->where('idempotency_key', $bill->idempotencyKey)->where('status', 'succeeded')->latest('id')->first();
        if ($previous) {
            $this->markPushed($document, $previous->connector, (string) $previous->external_ref, data_get($previous->response, '_url'), 'draft');

            return ['meta' => ['connector' => $previous->connector, 'external_ref' => $previous->external_ref, 'reused' => true]];
        }

        $start = hrtime(true);
        $log = fn (array $attrs) => ErpPush::withoutGlobalScopes()->create($attrs + [
            'company_id' => $document->company_id, 'document_id' => $document->id, 'connector' => $connector->name(),
            'idempotency_key' => $bill->idempotencyKey, 'attempt' => $this->attempts(),
            'duration_ms' => (int) ((hrtime(true) - $start) / 1e6),
        ]);

        try {
            $result = $connector->pushVendorBill($bill);
        } catch (Throwable $e) {
            $erp = $e instanceof ErpException ? $e : null;
            $log([
                'status' => 'failed', 'request' => $erp?->request ?: $bill->toArray(), 'response' => $erp?->response ?: null,
                'http_status' => $erp?->httpStatus, 'retryable' => $erp ? $erp->retryable : true, 'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            throw $e;
        }

        $log([
            'status' => 'succeeded', 'external_ref' => $result->externalRef, 'request' => $result->request ?: $bill->toArray(),
            'response' => $result->response + ['_url' => $result->url, '_already_existed' => $result->alreadyExisted], 'http_status' => $result->httpStatus,
        ]);
        $this->markPushed($document, $connector->name(), $result->externalRef, $result->url, $result->state);

        return ['meta' => [
            'connector' => $connector->name(), 'external_ref' => $result->externalRef, 'already_existed' => $result->alreadyExisted,
            'attempt' => $this->attempts(), 'idempotency_key' => $bill->idempotencyKey,
        ]];
    }

    private function markPushed(Document $document, string $connector, string $ref, ?string $url, string $state): void
    {
        $document->fill(['erp_connector' => $connector, 'erp_ref' => $ref, 'erp_url' => $url, 'pushed_at' => now(), 'erp_status' => $state, 'erp_status_at' => now(), 'last_error' => null]);
        $document->transitionTo(DocumentStatus::Pushed);
    }

    /** Dead-letter: the document waits in push_failed with the ERP's message; the last attempt is marked dead. */
    protected function onFinalFailure(Document $document, ?Throwable $e): void
    {
        ErpPush::withoutGlobalScopes()->where('document_id', $document->id)->where('status', 'failed')->latest('id')->limit(1)->update(['status' => 'dead']);
        if ($document->status === DocumentStatus::Matched) {
            $from = $document->transitionTo(DocumentStatus::PushFailed);
            app(EventRecorder::class)->record($document, 'push', 'failed', $from->value, DocumentStatus::PushFailed->value,
                attempt: $this->attempts(), error: $e?->getMessage(), meta: ['final' => true, 'dead_letter' => true]);
        }
    }
}
