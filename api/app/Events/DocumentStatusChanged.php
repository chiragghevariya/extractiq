<?php

namespace App\Events;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast over Reverb so the UI updates live. Queued (a socket outage never
 * breaks a pipeline job) and holds plain values captured at dispatch time:
 * serialising the model would re-load it later and report its *current*
 * status instead of the transition that actually happened.
 */
class DocumentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public int $documentId;

    public int $companyId;

    public string $from;

    public string $to;

    public string $type;

    public string $filename;

    public function __construct(Document $document, DocumentStatus $from)
    {
        $this->documentId = $document->id;
        $this->companyId = $document->company_id;
        $this->from = $from->value;
        $this->to = $document->status->value;
        $this->type = $document->type->value;
        $this->filename = $document->original_filename;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->companyId)];
    }

    public function broadcastAs(): string
    {
        return 'document.status';
    }

    public function broadcastWith(): array
    {
        return ['id' => (string) $this->documentId, 'from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'filename' => $this->filename];
    }
}
