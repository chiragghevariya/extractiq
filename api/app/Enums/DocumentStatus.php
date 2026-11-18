<?php

namespace App\Enums;

/**
 * received -> ocr_done -> extracted -> needs_review | validated -> approved
 *          -> matched | match_exception -> pushed | push_failed
 *          -> posted -> paid (bill status read back from the ERP by the scheduled sync)
 * received -> failed (OCR gave up after its retries; "retry" moves it back to received)
 * needs_review | validated -> rejected (a reviewer refused it with a reason; "reopen" → needs_review)
 */
enum DocumentStatus: string
{
    case Received = 'received';
    case OcrDone = 'ocr_done';
    case Extracted = 'extracted';
    case NeedsReview = 'needs_review';
    case Validated = 'validated';
    case Approved = 'approved';
    case Matched = 'matched';
    case MatchException = 'match_exception';
    case Pushed = 'pushed';
    case PushFailed = 'push_failed';
    case Posted = 'posted';
    case Paid = 'paid';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::OcrDone, self::Failed],
            self::Failed => [self::Received],
            self::OcrDone => [self::Extracted],
            // re-validation after reviewer corrections can flip between the two
            self::Extracted => [self::NeedsReview, self::Validated, self::Approved],
            self::NeedsReview, self::Validated => [self::NeedsReview, self::Validated, self::Approved, self::Rejected],
            self::Rejected => [self::NeedsReview],
            self::Approved => [self::Matched, self::MatchException],
            self::MatchException => [self::Matched, self::MatchException],
            self::Matched => [self::Pushed, self::PushFailed],
            self::PushFailed => [self::Pushed, self::PushFailed],
            // The ERP may report a bill straight as paid (e.g. paid on creation), so posted can be skipped.
            self::Pushed => [self::Posted, self::Paid],
            self::Posted => [self::Paid],
            self::Paid => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Extracted, self::NeedsReview, self::Validated], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OcrDone => 'OCR done',
            self::NeedsReview => 'Needs review',
            self::MatchException => 'Match exception',
            self::PushFailed => 'Push failed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
            default => ucfirst($this->value),
        };
    }

    /** Ordinal position in the happy path, used for "is the doc past step X". */
    public function rank(): int
    {
        return match ($this) {
            self::Received, self::Failed => 0,
            self::OcrDone => 1,
            self::Extracted => 2,
            self::NeedsReview, self::Validated, self::Rejected => 3,
            self::Approved => 4,
            self::Matched, self::MatchException => 5,
            self::Pushed, self::PushFailed => 6,
            self::Posted => 7,
            self::Paid => 8,
        };
    }
}
