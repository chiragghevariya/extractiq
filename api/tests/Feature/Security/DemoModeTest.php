<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->demo = User::factory()->role('admin')->create(['company_id' => $this->company->id, 'email' => 'demo@extractiq.test', 'is_demo' => true]);
});

it('only offers the demo login when DEMO_MODE is on', function () {
    config(['extractiq.demo.enabled' => false]);
    $this->postJson('/api/v1/auth/demo')->assertNotFound();

    config(['extractiq.demo.enabled' => true]);
    $this->postJson('/api/v1/auth/demo')->assertOk()
        ->assertJsonPath('data.attributes.is_demo', true)
        ->assertJsonPath('meta.demo', true)
        ->assertJsonPath('data.attributes.email', 'demo@extractiq.test');
});

it('lets the demo visitor read everything and change nothing', function () {
    Sanctum::actingAs($this->demo);
    $doc = Document::factory()->create(['company_id' => $this->company->id]);

    $this->getJson('/api/v1/documents')->assertOk();
    $this->getJson("/api/v1/documents/{$doc->id}")->assertOk();
    $this->getJson('/api/v1/settings/approvals')->assertOk();

    foreach ([
        ['POST', "/api/v1/documents/{$doc->id}/approve"], ['PATCH', "/api/v1/documents/{$doc->id}"], ['DELETE', "/api/v1/documents/{$doc->id}"],
        ['PATCH', '/api/v1/settings/matching'], ['PATCH', '/api/v1/settings/providers'], ['PATCH', "/api/v1/users/{$this->demo->id}"],
        ['POST', '/api/v1/erp/sync'], ['GET', '/api/v1/erp/quickbooks/connect'],
    ] as [$method, $path]) {
        $this->json($method, $path, ['role' => 'viewer'])->assertForbidden()->assertJsonPath('errors.0.code', 'demo_read_only');
    }
    expect(Document::find($doc->id))->not->toBeNull()->and($this->demo->fresh()->role->value)->toBe('admin');

    $this->postJson('/api/v1/auth/logout')->assertSuccessful();
});

it('resets the demo tenant only, and only in demo mode', function () {
    $other = Company::factory()->create(['slug' => 'acme']);
    $keep = Document::factory()->create(['company_id' => $other->id]);
    Document::factory()->count(2)->create(['company_id' => $this->company->id]);

    config(['extractiq.demo.enabled' => false]);
    $this->artisan('extractiq:demo-reset')->expectsOutputToContain('DEMO_MODE is off')->assertSuccessful();
    expect(Document::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(2);

    config(['extractiq.demo.enabled' => true]);
    $this->artisan('extractiq:demo-reset')->assertSuccessful();

    $demo = Company::where('slug', 'demo')->sole();
    expect($demo->id)->not->toBe($this->company->id)
        ->and(Document::withoutGlobalScopes()->where('company_id', $demo->id)->count())->toBe(0) // samples aren't loaded under test
        ->and(User::where('company_id', $demo->id)->where('is_demo', true)->count())->toBe(1)
        ->and(User::where('company_id', $demo->id)->pluck('role')->map->value->sort()->values()->all())->toBe(['admin', 'admin', 'reviewer', 'viewer'])
        ->and(Document::withoutGlobalScopes()->find($keep->id))->not->toBeNull();
});
