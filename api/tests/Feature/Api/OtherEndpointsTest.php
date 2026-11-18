<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\PushToErp;
use App\Models\Document;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    Sanctum::actingAs($this->user = userWithRole('admin', $this->company));
});

it('returns dashboard stats', function () {
    Document::factory()->count(2)->status(DocumentStatus::NeedsReview)->create(['company_id' => $this->company->id]);
    Document::factory()->status(DocumentStatus::Pushed)->create(['company_id' => $this->company->id]);

    $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('data.attributes.total_documents', 3)
        ->assertJsonPath('data.attributes.status_counts.needs_review', 2)
        ->assertJsonPath('data.attributes.needs_attention', 2)
        ->assertJsonPath('data.attributes.straight_through_rate', 1)
        ->assertJsonCount(14, 'data.attributes.volume');
});

it('returns recent activity', function () {
    ingestSample($this->company, '01-po-1001-kestrel.pdf');

    $this->getJson('/api/v1/activity')->assertOk()->assertJsonPath('data.0.type', 'document-events')
        ->assertJsonStructure(['data' => [['attributes' => ['document_filename', 'step']]]]);
});

it('manages vendors and rejects duplicates', function () {
    $this->postJson('/api/v1/vendors', ['name' => 'Nimbus Cloud Services Inc', 'default_currency' => 'usd'])
        ->assertCreated()->assertJsonPath('data.attributes.default_currency', 'USD');
    $this->postJson('/api/v1/vendors', ['name' => 'nimbus cloud services'])->assertStatus(422);

    $id = Vendor::first()->id;
    $this->patchJson("/api/v1/vendors/$id", ['erp_ref' => 'odoo:12'])->assertOk()->assertJsonPath('data.attributes.erp_ref', 'odoo:12');
    $this->getJson('/api/v1/vendors')->assertJsonCount(1, 'data');
});

it('lists open match exceptions', function () {
    $doc = Document::factory()->status(DocumentStatus::MatchException)->create(['company_id' => $this->company->id]);
    fakeMatch($doc);

    $this->getJson('/api/v1/matches?filter[result]=open')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.invoice.status', 'match_exception')
        ->assertJsonPath('meta.counts.open', 1);
});

it('describes integrations and tests the ERP connection', function () {
    $this->getJson('/api/v1/integrations')->assertOk()
        ->assertJsonPath('data.attributes.llm.driver', 'heuristic')
        ->assertJsonPath('data.attributes.erp.driver', 'mock')
        ->assertJsonPath('data.attributes.inbound_email', 'ap-demo@inbound.extractiq.test');
    $this->postJson('/api/v1/integrations/erp/test')->assertOk()->assertJsonPath('data.attributes.ok', true);
});

it('serves the OpenAPI spec and docs page', function () {
    $this->get('/api/docs')->assertOk()->assertSee('swagger-ui');
    $this->get('/api/docs/openapi.yaml')->assertOk();
});

it('lists ERP push attempts with a summary', function () {
    $doc = Document::factory()->status(DocumentStatus::Matched)->create(['company_id' => $this->company->id]);
    PushToErp::dispatchSync($doc->id);

    $this->getJson('/api/v1/erp-pushes')->assertOk()
        ->assertJsonPath('data.0.attributes.status', 'succeeded')
        ->assertJsonPath('data.0.attributes.connector', 'mock')
        ->assertJsonPath('data.0.attributes.document.id', (string) $doc->id)
        ->assertJsonPath('meta.summary.succeeded', 1);
    $this->getJson('/api/v1/erp-pushes?filter[status]=failed')->assertJsonCount(0, 'data');
});
