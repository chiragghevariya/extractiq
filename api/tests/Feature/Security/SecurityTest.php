<?php

use App\Logging\MaskSensitiveData;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Support\Pii;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    $this->company = demoCompany();
});

it('masks IBANs, labelled account numbers, SWIFT codes and long digit runs', function (string $in, string $out) {
    expect(Pii::mask($in))->toBe($out);
})->with([
    ['IBAN DE89 3704 0044 0532 0130 00, BIC COBADEFFXXX', 'IBAN DE89 •••• 3000, BIC •••• FXXX'],
    ['iban GB33BUKB20201555555555', 'iban GB33 •••• 5555'],
    ['Account number: 12345678901', 'Account number: •••• 8901'],
    ['Pay to acct 000123456789 by Friday', 'Pay to acct •••• 6789 by Friday'],
    ['Routing number 021000021', 'Routing number •••• 0021'],
    ['wire to 4111 1111 1111 1111 please', 'wire to •••• 1111 please'],
    // Not bank details: amounts, dates, document numbers, short ids stay readable.
    ['Total 1,234,567.89 on 2026-11-14 for INV-K-2041 (PO-1001, 40 units)', 'Total 1,234,567.89 on 2026-11-14 for INV-K-2041 (PO-1001, 40 units)'],
]);

it('masks bank details in log records (message, context and extra)', function () {
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Warning, 'Push failed for IBAN DE89370400440532013000',
        ['payload' => ['bank' => ['account' => 'DE89 3704 0044 0532 0130 00']], 'note' => 'acct 99887766554433']);
    $masked = (new MaskSensitiveData)($record);

    expect($masked->message)->toBe('Push failed for IBAN DE89 •••• 3000')
        ->and($masked->context['payload']['bank']['account'])->toBe('DE89 •••• 3000')
        ->and($masked->context['note'])->toBe('acct •••• 4433')
        ->and(collect(config('logging.channels'))->filter(fn ($c) => in_array(App\Logging\MaskPiiTap::class, $c['tap'] ?? [], true))->keys()->all())
        ->toContain('single', 'daily', 'stderr');
});

it('masks extracted bank details for viewers only', function () {
    $doc = Document::factory()->create(['company_id' => $this->company->id, 'extraction' => ['data' => ['bank' => ['account' => ['value' => 'DE89 3704 0044 0532 0130 00'], 'swift' => ['value' => 'COBADEFFXXX']]]]]);

    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $this->getJson("/api/v1/documents/{$doc->id}")->assertOk()
        ->assertJsonPath('data.attributes.extracted.bank.account.value', '•••• 3000')
        ->assertJsonPath('data.attributes.extracted.bank.swift.value', '•••• FXXX');

    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $this->getJson("/api/v1/documents/{$doc->id}")->assertJsonPath('data.attributes.extracted.bank.account.value', 'DE89 3704 0044 0532 0130 00');
});

it('hands out short-lived signed links to originals and page images, and refuses tampered or expired ones', function () {
    Sanctum::actingAs(userWithRole('viewer', $this->company));
    $doc = ingestSample($this->company, '03-inv-k-2041-kestrel-3way-match.pdf');

    $link = $this->getJson("/api/v1/documents/{$doc->id}/file-url")->assertOk()->json('data.attributes');
    expect($link['mime_type'])->toBe('application/pdf')->and($link['url'])->toContain('signature=')->toContain("files/{$doc->uuid}");

    auth()->forgetGuards(); // the signature is the only credential
    $this->get($link['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(str_replace('signature=', 'signature=0', $link['url']))->assertForbidden();
    $this->get(URL::temporarySignedRoute('files.signed', now()->subMinute(), ['uuid' => $doc->uuid]))->assertForbidden();
    $this->get("/api/v1/files/{$doc->uuid}")->assertForbidden();
});

it('uses presigned S3 URLs when documents live on S3', function () {
    config(['filesystems.disks.s3' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'docs', 'endpoint' => 'http://127.0.0.1:9100', 'use_path_style_endpoint' => true]]);
    $doc = Document::factory()->create(['company_id' => $this->company->id, 'storage_disk' => 's3', 'storage_path' => 'demo/a.pdf', 'mime_type' => 'application/pdf']);
    Sanctum::actingAs(userWithRole('viewer', $this->company));

    $url = $this->getJson("/api/v1/documents/{$doc->id}/file-url")->assertOk()->json('data.attributes.url');
    expect($url)->toStartWith('http://127.0.0.1:9100/docs/demo/a.pdf?')->toContain('X-Amz-Signature=')->toContain('X-Amz-Expires=300');
});

it('rate-limits uploads, exports and login', function () {
    config(['extractiq.security.rate_limits.exports' => 2, 'extractiq.security.rate_limits.login' => 3]);
    Sanctum::actingAs(userWithRole('admin', $this->company));

    $this->get('/api/v1/audit-log/export')->assertOk();
    $this->get('/api/v1/audit-log/export')->assertOk();
    $this->get('/api/v1/audit-log/export')->assertStatus(429);

    auth()->forgetGuards();
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'nope'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'nope'])->assertStatus(429);
});

it('exports the audit log as CSV with masked bank details and formula-safe cells', function () {
    $admin = userWithRole('admin', $this->company);
    $doc = Document::factory()->create(['company_id' => $this->company->id, 'original_filename' => '=HYPERLINK("x").pdf']);
    DocumentEvent::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'document_id' => $doc->id, 'user_id' => $admin->id, 'step' => 'review', 'status' => 'succeeded',
        'meta' => ['diff' => ['fields' => ['bank.account' => ['before' => null, 'after' => 'DE89 3704 0044 0532 0130 00'], 'total' => ['before' => '100.00', 'after' => '120.00']]]], 'created_at' => now()]);
    DocumentEvent::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'document_id' => $doc->id, 'step' => 'push', 'status' => 'failed', 'error' => 'ERP rejected IBAN DE89370400440532013000', 'created_at' => now()]);

    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $this->get('/api/v1/audit-log/export')->assertForbidden();

    Sanctum::actingAs($admin);
    $res = $this->get('/api/v1/audit-log/export?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $rows = array_map(fn ($l) => str_getcsv($l, escape: '\\'), explode("\n", trim($res->streamedContent())));

    expect($rows[0])->toBe(App\Http\Controllers\Api\V1\AuditLogController::COLUMNS)->and($rows)->toHaveCount(3)
        ->and($rows[1][3])->toBe("'=HYPERLINK(\"x\").pdf")
        ->and($rows[1][9])->toBe($admin->name)
        ->and($rows[1][14])->toBe('bank.account: — → DE89 •••• 3000; total: 100.00 → 120.00')
        ->and($rows[2][9])->toBe('system')
        ->and($rows[2][13])->toBe('ERP rejected IBAN DE89 •••• 3000');

    $this->get('/api/v1/audit-log/export?from=2024-01-01&to=2026-01-01')->assertStatus(422);
});

it('reports health: ok, degraded without workers, and never leaks connection details', function () {
    $res = $this->getJson('/api/health');

    expect($res->status())->toBe(200)
        ->and($res->json('checks.database.ok'))->toBeTrue()
        ->and($res->json('status'))->toBeIn(['ok', 'degraded'])
        ->and(json_encode($res->json()))->not->toContain((string) config('database.connections.pgsql.password') ?: 'never-empty')->not->toContain('127.0.0.1');
});
