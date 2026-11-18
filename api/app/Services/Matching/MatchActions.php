<?php

namespace App\Services\Matching;

use App\Enums\DocumentStatus;
use App\Jobs\PushToErp;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\MatchLine;
use App\Models\User;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person can do about a match exception. Every action needs a reason and writes a
 * document_events row; accepting a variance also needs the role set in the company's
 * matching settings (admin by default).
 */
class MatchActions
{
    public function __construct(private readonly EventRecorder $events) {}

    /** Accept one line's variance. When nothing else is open, the invoice is matched and goes to the ERP. */
    public function acceptLine(Document $invoice, MatchLine $line, User $user, string $reason): DocumentMatch
    {
        $match = $this->currentException($invoice);
        $this->authorize($invoice, $user);
        if ($line->document_match_id !== $match->id) {
            throw ValidationException::withMessages(['line' => 'That line belongs to an older match run.']);
        }
        if ($line->isOk()) {
            throw ValidationException::withMessages(['line' => 'That line has no open variance.']);
        }
        $line->update(['accepted_by' => $user->id, 'accepted_at' => now(), 'accept_reason' => $reason]);
        $this->events->record($invoice, 'match_accept', 'succeeded', meta: [
            'line_no' => $line->line_no, 'result' => $line->result, 'disputed_minor' => $line->disputed_minor, 'reason' => $reason, 'match_id' => $match->id,
        ], userId: $user->id);

        $match->load('lines');
        if (! $match->header_issues && $match->lines->every(fn (MatchLine $l) => $l->isOk())) {
            $this->finalize($invoice, $match, $user, $reason);
        }

        return $match->fresh('lines');
    }

    /** Accept every open variance (and header issue) on the invoice at once. */
    public function acceptAll(Document $invoice, User $user, string $reason): DocumentMatch
    {
        $match = $this->currentException($invoice);
        $this->authorize($invoice, $user);
        DB::transaction(function () use ($match, $user, $reason) {
            MatchLine::withoutGlobalScopes()->where('document_match_id', $match->id)->whereNull('accepted_at')->where('result', '!=', 'matched')
                ->update(['accepted_by' => $user->id, 'accepted_at' => now(), 'accept_reason' => $reason]);
        });
        $this->finalize($invoice, $match, $user, $reason);

        return $match->fresh('lines');
    }

    /** Ask the vendor for a credit note for the disputed amount. The invoice stays in exception. */
    public function requestCreditNote(Document $invoice, User $user, string $reason, ?int $amountMinor = null): DocumentMatch
    {
        $match = $this->currentException($invoice);
        $amount = $amountMinor ?? (int) ($match->totals['disputed_minor'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'There is no disputed amount to credit.']);
        }
        $match->update([
            'credit_note_requested_by' => $user->id, 'credit_note_requested_at' => now(),
            'credit_note_amount_minor' => $amount, 'credit_note_reason' => $reason,
        ]);
        $this->events->record($invoice, 'credit_note', 'succeeded', meta: [
            'amount_minor' => $amount, 'currency' => $invoice->currency, 'reason' => $reason, 'match_id' => $match->id,
        ], userId: $user->id);

        return $match;
    }

    /** Stop the invoice from going to the ERP (and from being accepted into it) until released. */
    public function hold(Document $invoice, User $user, string $reason): Document
    {
        if ($invoice->onHold()) {
            throw ValidationException::withMessages(['hold' => 'Already on hold.']);
        }
        if (in_array($invoice->status, [DocumentStatus::Pushed, DocumentStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => "A {$invoice->status->label()} document can't be put on hold."]);
        }
        $invoice->forceFill(['on_hold_at' => now(), 'hold_reason' => $reason, 'held_by' => $user->id])->save();
        $this->events->record($invoice, 'hold', 'succeeded', meta: ['reason' => $reason], userId: $user->id);

        return $invoice;
    }

    public function release(Document $invoice, User $user, ?string $note = null): Document
    {
        if (! $invoice->onHold()) {
            throw ValidationException::withMessages(['hold' => 'This document is not on hold.']);
        }
        $was = $invoice->hold_reason;
        $invoice->forceFill(['on_hold_at' => null, 'hold_reason' => null, 'held_by' => null])->save();
        $this->events->record($invoice, 'release', 'succeeded', meta: array_filter(['held_for' => $was, 'note' => $note]), userId: $user->id);
        if (in_array($invoice->status, [DocumentStatus::Matched, DocumentStatus::PushFailed], true)) {
            PushToErp::dispatch($invoice->id);
        }

        return $invoice;
    }

    private function finalize(Document $invoice, DocumentMatch $match, User $user, string $reason): void
    {
        $match->update(['result' => 'accepted', 'accepted_by' => $user->id, 'accepted_at' => now(), 'accept_reason' => $reason]);
        $invoice->transitionTo(DocumentStatus::Matched);
        $this->events->record($invoice, 'match_override', 'succeeded', DocumentStatus::MatchException->value, DocumentStatus::Matched->value,
            meta: ['reason' => $reason, 'match_id' => $match->id, 'disputed_minor' => $match->totals['disputed_minor'] ?? 0], userId: $user->id);
        if (! $invoice->onHold()) {
            PushToErp::dispatch($invoice->id);
        }
    }

    private function currentException(Document $invoice): DocumentMatch
    {
        if ($invoice->status !== DocumentStatus::MatchException) {
            throw ValidationException::withMessages(['status' => 'Only invoices with a match exception can be resolved this way.']);
        }
        $match = DocumentMatch::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('is_current', true)->latest('id')->first();
        if (! $match) {
            throw ValidationException::withMessages(['match' => 'This invoice has no match yet.']);
        }

        return $match;
    }

    private function authorize(Document $invoice, User $user): void
    {
        if (! MatchSettings::for($invoice->company_id)->canAccept($user)) {
            throw new AuthorizationException('Accepting a variance needs the admin role in this workspace.');
        }
    }
}
