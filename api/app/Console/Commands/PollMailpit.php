<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Mail\ImapMailbox;
use App\Services\Mail\InboundMailbox;
use App\Services\Mail\MailpitMailbox;
use App\Services\Pipeline\EmailIngestor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Email ingestion: every company has an inbox (ap-{slug}@inbound.extractiq.test).
 * Reads unread mail from the configured mailbox (Mailpit locally, IMAP elsewhere),
 * ingests PDF/image attachments with sender + subject, and marks those messages read.
 * Scheduled every minute (routes/console.php).
 */
class PollMailpit extends Command
{
    protected $signature = 'extractiq:poll-inbox {--driver= : mailpit or imap (default: INBOUND_MAIL_DRIVER)} {--limit=50}';

    protected $aliases = ['extractiq:poll-mailpit'];

    protected $description = 'Ingest invoice attachments from the inbound mailbox (Mailpit or IMAP)';

    public function handle(EmailIngestor $ingestor): int
    {
        $mailbox = $this->mailbox();
        $inboxes = Company::pluck('inbound_email')->map(fn ($e) => strtolower($e))->all();

        $count = 0;
        try {
            foreach ($mailbox->unread($inboxes) as $message) {
                $result = $ingestor->ingest($message->toArray());
                $mailbox->markProcessed($message);
                $count++;
                $this->line(sprintf('%s  "%s"  created=%d duplicates=%d ignored=%d',
                    $message->id, $message->subject ?? '', $result['created'], $result['duplicates'], $result['ignored']));
            }
        } catch (Throwable $e) {
            $this->error("{$mailbox->name()}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("$count message(s) processed via {$mailbox->name()}.");

        return self::SUCCESS;
    }

    private function mailbox(): InboundMailbox
    {
        if (app()->bound(InboundMailbox::class)) {
            return app(InboundMailbox::class);
        }

        return match ($this->option('driver') ?: config('extractiq.inbound_email.driver')) {
            'imap' => new ImapMailbox(config('extractiq.inbound_email.imap')),
            default => new MailpitMailbox(config('extractiq.inbound_email.mailpit_url'), (int) $this->option('limit')),
        };
    }
}
