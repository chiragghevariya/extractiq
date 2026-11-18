<?php

namespace App\Services\Mail;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Any IMAP server (e.g. a catch-all mailbox for *@inbound.yourdomain). Reads unseen
 * messages without marking them, ingests those addressed to a tenant inbox, then
 * flags them \Seen. Uses webklex/php-imap (pure PHP; no ext-imap needed).
 */
class ImapMailbox implements InboundMailbox
{
    private ?Client $client = null;

    /** @var array<string, Message> */
    private array $open = [];

    public function __construct(private readonly array $config, ?Client $client = null)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'imap';
    }

    public function unread(array $inboxes): iterable
    {
        $folder = $this->client()->getFolderByPath($this->config['folder'] ?? 'INBOX');
        if (! $folder) {
            throw new RuntimeException('IMAP folder not found: '.($this->config['folder'] ?? 'INBOX'));
        }

        foreach ($folder->query()->whereUnseen()->leaveUnread()->setFetchBody(true)->get() as $message) {
            $to = [];
            foreach (['to', 'cc', 'delivered_to'] as $header) {
                foreach ($this->addresses($message, $header) as $addr) {
                    $to[] = strtolower($addr);
                }
            }
            if (! array_intersect($to, $inboxes)) {
                continue;
            }

            $attachments = [];
            foreach ($message->getAttachments() as $att) {
                $attachments[] = [
                    'filename' => (string) ($att->name ?: 'attachment'),
                    'content_type' => strtolower((string) ($att->getMimeType() ?: $att->content_type)),
                    'content' => (string) $att->content,
                ];
            }

            $id = (string) $message->getUid();
            $this->open[$id] = $message;
            $from = $this->addresses($message, 'from')[0] ?? null;
            $subject = (string) $message->getSubject();
            $messageId = (string) $message->getMessageId();

            yield new InboundMessage($id, $from, array_values(array_unique($to)), $subject ?: null, $messageId ?: null, $attachments);
        }
    }

    public function markProcessed(InboundMessage $message): void
    {
        ($this->open[$message->id] ?? null)?->setFlag('Seen');
        unset($this->open[$message->id]);
    }

    /** @return list<string> */
    private function addresses(Message $message, string $header): array
    {
        $attr = $message->get($header);
        if (! $attr) {
            return [];
        }
        $out = [];
        foreach ($attr->toArray() as $a) {
            $mail = is_object($a) ? ($a->mail ?? null) : (is_string($a) ? $a : null);
            if ($mail) {
                $out[] = $mail;
            }
        }

        return $out;
    }

    private function client(): Client
    {
        if (! $this->client) {
            if (empty($this->config['host']) || empty($this->config['username'])) {
                throw new RuntimeException('IMAP_HOST / IMAP_USERNAME are not set.');
            }
            $this->client = (new ClientManager)->make([
                'host' => $this->config['host'],
                'port' => $this->config['port'] ?? 993,
                'encryption' => $this->config['encryption'] ?? 'ssl',
                'validate_cert' => true,
                'username' => $this->config['username'],
                'password' => $this->config['password'],
                'protocol' => 'imap',
            ]);
            $this->client->connect();
        }

        return $this->client;
    }
}
