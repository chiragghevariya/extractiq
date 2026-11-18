<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Local development: Mailpit's REST API (http://127.0.0.1:8025). */
class MailpitMailbox implements InboundMailbox
{
    public function __construct(private readonly string $baseUrl, private readonly int $limit = 50) {}

    public function name(): string
    {
        return 'mailpit';
    }

    public function unread(array $inboxes): iterable
    {
        $base = rtrim($this->baseUrl, '/');
        $list = Http::timeout(10)->get("$base/api/v1/messages", ['limit' => $this->limit]);
        if ($list->failed()) {
            throw new RuntimeException("Mailpit not reachable at $base");
        }

        foreach ($list->json('messages', []) as $summary) {
            $to = array_map(fn ($t) => strtolower($t['Address'] ?? ''), array_merge($summary['To'] ?? [], $summary['Cc'] ?? []));
            if (($summary['Read'] ?? false) || ! array_intersect($to, $inboxes)) {
                continue; // someone else's mail (Mailpit is often shared between local apps)
            }
            $msg = Http::get("$base/api/v1/message/{$summary['ID']}")->json();
            $attachments = [];
            foreach ($msg['Attachments'] ?? [] as $a) {
                $attachments[] = [
                    'filename' => (string) $a['FileName'],
                    'content_type' => strtolower((string) $a['ContentType']),
                    'content' => Http::get("$base/api/v1/message/{$summary['ID']}/part/{$a['PartID']}")->body(),
                ];
            }
            yield new InboundMessage((string) $summary['ID'], $msg['From']['Address'] ?? null, $to, $msg['Subject'] ?? null, $msg['MessageID'] ?? null, $attachments);
        }
    }

    public function markProcessed(InboundMessage $message): void
    {
        Http::put(rtrim($this->baseUrl, '/').'/api/v1/messages', ['IDs' => [$message->id], 'Read' => true]);
    }
}
