<?php

use App\Models\Document;
use Illuminate\Support\Facades\Http;

function signedEmail($test, array $payload, string $secret = 'test-secret')
{
    $body = json_encode($payload);

    return $test->call('POST', '/api/v1/inbound/email', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_EXTRACTIQ_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

beforeEach(function () {
    $this->company = demoCompany();
    $this->payload = [
        'from' => 'billing@kestrel-parts.example',
        'to' => ['Accounts Payable <ap-demo@inbound.extractiq.test>'],
        'subject' => 'Invoice INV-K-2041',
        'attachments' => [
            ['filename' => 'inv.pdf', 'content_type' => 'application/pdf', 'content' => base64_encode(file_get_contents(samplePath('03-inv-k-2041-kestrel-3way-match.pdf')))],
            ['filename' => 'logo.gif', 'content_type' => 'image/gif', 'content' => base64_encode('GIF89a')],
        ],
    ];
});

it('ingests attachments from a signed inbound email', function () {
    signedEmail($this, $this->payload)->assertStatus(202)->assertJsonPath('meta.created', 1)->assertJsonPath('meta.ignored', 1);

    $doc = Document::withoutGlobalScopes()->first();
    expect($doc->source)->toBe('email')->and($doc->email_from)->toBe('billing@kestrel-parts.example')
        ->and($doc->company_id)->toBe($this->company->id)->and($doc->document_number)->toBe('INV-K-2041');
});

it('rejects a bad signature', function () {
    signedEmail($this, $this->payload, 'wrong')->assertStatus(401)->assertJsonPath('errors.0.code', 'bad_signature');
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('returns 404 for an unknown recipient', function () {
    signedEmail($this, ['to' => 'nobody@else.test'] + $this->payload)->assertStatus(404);
});

it('polls Mailpit and marks messages read', function () {
    Http::fake([
        '*/api/v1/messages?*' => Http::response(['messages' => [
            ['ID' => 'm1', 'Read' => false, 'To' => [['Address' => 'AP-demo@inbound.extractiq.test']]],
            ['ID' => 'm2', 'Read' => true, 'To' => [['Address' => 'ap-demo@inbound.extractiq.test']]],
            ['ID' => 'other-app', 'Read' => false, 'To' => [['Address' => 'support@another-local-app.test']]],
        ]]),
        '*/api/v1/message/m1' => Http::response([
            'From' => ['Address' => 'ar@brightline-office.example'], 'To' => [['Address' => 'ap-demo@inbound.extractiq.test']],
            'Subject' => 'Invoice', 'MessageID' => 'abc@x', 'Attachments' => [['FileName' => 'po.pdf', 'ContentType' => 'application/pdf', 'PartID' => '2']],
        ]),
        '*/api/v1/message/m1/part/2' => Http::response(file_get_contents(samplePath('04-po-1002-brightline.pdf'))),
        '*/api/v1/messages' => Http::response('ok'),
    ]);

    $this->artisan('extractiq:poll-mailpit')->assertSuccessful();

    expect(Document::withoutGlobalScopes()->where('source', 'email')->count())->toBe(1);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['IDs'] === ['m1'] && $r['Read'] === true);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'other-app'));
});
