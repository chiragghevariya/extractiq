<?php

namespace App\Services\Mail;

/** A mailbox ExtractIQ polls for supplier emails. */
interface InboundMailbox
{
    /**
     * Unread messages addressed to one of $inboxes. Messages for other recipients must be left untouched.
     *
     * @param  list<string>  $inboxes  lower-case tenant inbox addresses
     * @return iterable<InboundMessage>
     */
    public function unread(array $inboxes): iterable;

    /** Mark a message as handled so it's not ingested again. */
    public function markProcessed(InboundMessage $message): void;

    public function name(): string;
}
