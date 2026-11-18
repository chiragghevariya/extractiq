<?php

use App\Enums\DocumentStatus as S;

it('allows received → failed → received and nothing else from failed', function () {
    expect(S::Received->canTransitionTo(S::Failed))->toBeTrue()
        ->and(S::Failed->canTransitionTo(S::Received))->toBeTrue()
        ->and(S::Failed->canTransitionTo(S::OcrDone))->toBeFalse()
        ->and(S::Failed->label())->toBe('Failed');
});
