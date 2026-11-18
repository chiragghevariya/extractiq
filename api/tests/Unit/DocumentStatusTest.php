<?php

use App\Enums\DocumentStatus as S;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Document;

it('allows the happy path', function () {
    $path = [S::Received, S::OcrDone, S::Extracted, S::Validated, S::Approved, S::Matched, S::Pushed];
    for ($i = 0; $i < count($path) - 1; $i++) {
        expect($path[$i]->canTransitionTo($path[$i + 1]))->toBeTrue("{$path[$i]->value} -> {$path[$i + 1]->value}");
    }
});

it('allows review, exception and retry branches', function () {
    expect(S::Extracted->canTransitionTo(S::NeedsReview))->toBeTrue()
        ->and(S::NeedsReview->canTransitionTo(S::Approved))->toBeTrue()
        ->and(S::Approved->canTransitionTo(S::MatchException))->toBeTrue()
        ->and(S::MatchException->canTransitionTo(S::Matched))->toBeTrue()
        ->and(S::Matched->canTransitionTo(S::PushFailed))->toBeTrue()
        ->and(S::PushFailed->canTransitionTo(S::Pushed))->toBeTrue();
});

it('rejects skipping steps or moving backwards', function (S $from, S $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    [S::Received, S::Extracted],
    [S::OcrDone, S::Validated],
    [S::Extracted, S::Matched],
    [S::Approved, S::Pushed],
    [S::Pushed, S::Received],
    [S::Matched, S::Approved],
]);

it('throws on an invalid model transition', function () {
    $doc = Document::factory()->status(S::Received)->create();
    $doc->transitionTo(S::Pushed);
})->throws(InvalidStatusTransition::class);

it('only exposes editing during review', function () {
    expect(S::NeedsReview->isEditable())->toBeTrue()
        ->and(S::Validated->isEditable())->toBeTrue()
        ->and(S::Approved->isEditable())->toBeFalse()
        ->and(S::Received->isEditable())->toBeFalse();
});
