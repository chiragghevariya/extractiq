<?php

namespace App\Services\Mail;

final class InboundMessage
{
    /**
     * @param  list<string>  $to  recipient addresses (lower-case)
     * @param  list<array{filename: string, content_type: string, content: string}>  $attachments  raw bytes
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $from,
        public readonly array $to,
        public readonly ?string $subject,
        public readonly ?string $messageId,
        public readonly array $attachments,
    ) {}

    /** @return array<string, mixed> shape expected by EmailIngestor */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'subject' => $this->subject, 'message_id' => $this->messageId, 'attachments' => $this->attachments];
    }
}
