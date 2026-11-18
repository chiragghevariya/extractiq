<?php

use App\Models\Company;
use App\Models\Document;
use App\Services\Mail\InboundMailbox;
use App\Services\Mail\InboundMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/** In-memory mailbox standing in for Mailpit/IMAP. */
class FakeMailbox implements InboundMailbox
{
    public array $processed = [];

    public function __construct(public array $messages) {}

    public function unread(array $inboxes): iterable
    {
        foreach ($this->messages as $m) {
            if (array_intersect($m->to, $inboxes)) {
                yield $m;
            }
        }
    }

    public function markProcessed(InboundMessage $message): void
    {
        $this->processed[] = $message->id;
    }

    public function name(): string
    {
        return 'fake';
    }
}

it('ingests attachments for each company inbox and records sender and subject', function () {
    Queue::fake();
    $demo = demoCompany();
    $other = Company::factory()->create(['slug' => 'globex']);
    $pdf = file_get_contents(samplePath('01-po-1001-kestrel.pdf'));
    $box = new FakeMailbox([
        new InboundMessage('1', 'billing@kestrel-parts.example', ['ap-demo@inbound.extractiq.test'], 'PO 1001', '<a@x>', [
            ['filename' => 'po.pdf', 'content_type' => 'application/pdf', 'content' => $pdf],
            ['filename' => 'logo.gif', 'content_type' => 'image/gif', 'content' => 'GIF89a'],
        ]),
        new InboundMessage('2', 'ar@globex.example', ['ap-globex@inbound.extractiq.test'], 'Invoice', null, [
            ['filename' => 'scan.png', 'content_type' => 'image/png', 'content' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')],
        ]),
        new InboundMessage('3', 'spam@example.com', ['someone@else.test'], 'Hi', null, []),
    ]);
    app()->instance(InboundMailbox::class, $box);

    $this->artisan('extractiq:poll-inbox')->assertSuccessful();

    $demoDoc = Document::withoutGlobalScopes()->where('company_id', $demo->id)->sole();
    expect($demoDoc->source)->toBe('email')
        ->and($demoDoc->email_from)->toBe('billing@kestrel-parts.example')
        ->and($demoDoc->email_subject)->toBe('PO 1001')
        ->and($demoDoc->uploaded_by)->toBeNull()
        ->and(Document::withoutGlobalScopes()->where('company_id', $other->id)->count())->toBe(1)
        ->and($box->processed)->toBe(['1', '2']);
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'extractiq:poll-inbox'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
