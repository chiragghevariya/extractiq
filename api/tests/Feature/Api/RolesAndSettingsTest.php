<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Llm\LlmManager;
use App\Services\Ocr\OcrManager;
use App\Services\Settings\ApprovalLimits;
use App\Services\Settings\ProviderSettings;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->admin = userWithRole('admin', $this->company);
    $this->reviewer = userWithRole('reviewer', $this->company);
    $this->invoice = fn (int $totalMinor, string $currency = 'USD') => Document::factory()->status(DocumentStatus::Validated)->create([
        'company_id' => $this->company->id, 'type' => 'invoice', 'total_minor' => $totalMinor, 'currency' => $currency, 'validation' => ['errors' => [], 'warnings' => []],
    ]);
});

it('has sensible default approval limits: admins unlimited, reviewers 10,000, viewers none', function () {
    $limits = ApprovalLimits::for($this->company);

    expect($limits->limitFor($this->admin))->toBeNull()
        ->and($limits->limitFor($this->reviewer))->toBe(10000.0)
        ->and($limits->limitFor(userWithRole('viewer', $this->company)))->toBe(0.0);
});

it('blocks approval above the reviewer limit and allows it for an admin', function () {
    $big = ($this->invoice)(1_250_000); // USD 12,500.00
    $small = ($this->invoice)(99_900);

    Sanctum::actingAs($this->reviewer);
    $this->postJson("/api/v1/documents/{$small->id}/approve")->assertOk();
    $this->postJson("/api/v1/documents/{$big->id}/approve")->assertForbidden()
        ->assertJsonPath('errors.0.code', 'forbidden')
        ->assertJsonPath('errors.0.detail', 'This invoice is above your approval limit (USD 10,000.00). Someone with a higher limit needs to approve it.');
    expect($big->fresh()->status)->toBe(DocumentStatus::Validated);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/documents/{$big->id}/approve")->assertOk();
    expect($big->fresh()->status)->not->toBe(DocumentStatus::Validated);
});

it('compares in the invoice currency, including zero-decimal currencies', function () {
    $limits = new ApprovalLimits(['admin' => null, 'reviewer' => 10000.0]);

    expect($limits->allows($this->reviewer, ($this->invoice)(1_000_000, 'EUR')))->toBeTrue()   // EUR 10,000.00
        ->and($limits->allows($this->reviewer, ($this->invoice)(1_000_001, 'EUR')))->toBeFalse()
        ->and($limits->allows($this->reviewer, ($this->invoice)(10_000, 'JPY')))->toBeTrue()     // JPY 10,000
        ->and($limits->allows($this->reviewer, ($this->invoice)(10_001, 'JPY')))->toBeFalse();
});

it('lets admins change role limits and per-user limits; a user limit replaces the role limit', function () {
    Sanctum::actingAs($this->admin);
    $this->patchJson('/api/v1/settings/approvals', ['reviewer' => 500, 'admin' => null])->assertOk()
        ->assertJsonPath('data.reviewer', 500)->assertJsonPath('data.admin', null);
    $this->patchJson("/api/v1/users/{$this->reviewer->id}", ['approval_limit' => 25000])->assertOk()
        ->assertJsonPath('data.attributes.approval_limit', 25000)->assertJsonPath('data.attributes.effective_approval_limit', 25000);

    $users = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->keyBy('id');
    expect($users[(string) $this->reviewer->id]['attributes']['effective_approval_limit'])->toEqual(25000);

    Sanctum::actingAs($this->reviewer->fresh());
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.attributes.approval_limit', 25000);
    $this->postJson('/api/v1/documents/'.($this->invoice)(2_000_000)->id.'/approve')->assertOk();
});

it('keeps settings and people admin-only, and keeps at least one admin', function () {
    Sanctum::actingAs($this->reviewer);
    $this->patchJson('/api/v1/settings/approvals', ['reviewer' => null])->assertForbidden();
    $this->patchJson("/api/v1/users/{$this->reviewer->id}", ['role' => 'admin'])->assertForbidden();
    $this->patchJson('/api/v1/settings/providers', ['llm' => 'heuristic'])->assertForbidden();
    $this->getJson('/api/v1/settings/approvals')->assertOk();

    Sanctum::actingAs($this->admin);
    $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'reviewer'])->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'A workspace needs at least one admin.');
    $this->patchJson("/api/v1/users/{$this->reviewer->id}", ['role' => 'viewer'])->assertOk()->assertJsonPath('data.attributes.role', 'viewer');

    $other = userWithRole('admin', App\Models\Company::factory()->create());
    $this->patchJson("/api/v1/users/{$other->id}", ['role' => 'viewer'])->assertNotFound();
});

it('stores the OCR and LLM provider per company and the pipeline uses it', function () {
    config(['extractiq.llm.default' => 'heuristic', 'extractiq.llm.drivers.anthropic.api_key' => null, 'extractiq.ocr.default' => 'pdftext']);
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/settings/providers')->assertOk()
        ->assertJsonPath('data.llm', 'heuristic')
        ->assertJsonPath('meta.available.llm.anthropic.configured', false)
        ->assertJsonPath('meta.available.llm.heuristic.configured', true);
    $this->patchJson('/api/v1/settings/providers', ['llm' => 'anthropic'])->assertStatus(422);
    $this->patchJson('/api/v1/settings/providers', ['ocr' => 'tesseract'])->assertOk()->assertJsonPath('data.ocr', 'tesseract');

    expect(ProviderSettings::for($this->company->fresh())->ocr)->toBe('tesseract')
        ->and(app(OcrManager::class)->forCompany($this->company->id)->name())->toBe('tesseract')
        ->and(app(LlmManager::class)->forCompany($this->company->id)->name())->toBe('heuristic');

    // A key removed later: the stored choice falls back to the server default instead of failing.
    config(['extractiq.llm.drivers.anthropic.api_key' => 'sk-test']);
    $this->patchJson('/api/v1/settings/providers', ['llm' => 'anthropic'])->assertOk();
    config(['extractiq.llm.drivers.anthropic.api_key' => null]);
    expect(ProviderSettings::for($this->company->fresh())->llm)->toBe('heuristic');
});
