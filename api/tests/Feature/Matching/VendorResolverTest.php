<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Llm\Drivers\HeuristicLlm;
use App\Services\Llm\LlmManager;
use App\Services\Matching\VendorResolver;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->kestrel = mdVendor($this->company, 'Kestrel Industrial Parts LLC', ['tax_id' => '88-1234567', 'aliases' => ['KIP', 'Kestrel Parts']]);
    $this->harbor = mdVendor($this->company, 'Harbor & Pine Packaging Ltd', ['tax_id' => 'DE123456789']);
    $this->resolve = fn (array $x) => app(VendorResolver::class)->resolve($this->company->id, $x);
});

it('1. matches on tax ID first, whatever the formatting', function () {
    $r = ($this->resolve)(['name' => 'Something else entirely', 'tax_id' => 'de 123 456 789']);
    expect($r['vendor']->id)->toBe($this->harbor->id)->and($r['method'])->toBe('tax_id')->and($r['confidence'])->toBe(1.0);
});

it('2. matches an exact name or a known alias', function () {
    expect(($this->resolve)(['name' => 'KESTREL INDUSTRIAL PARTS, L.L.C.'])['method'])->toBe('name');

    $r = ($this->resolve)(['name' => 'Kestrel Parts']);
    expect($r['vendor']->id)->toBe($this->kestrel->id)->and($r['method'])->toBe('alias')->and($r['confidence'])->toBe(0.98);
});

it('3. falls back to fuzzy (trigram) name matching when one candidate clearly wins', function () {
    $r = ($this->resolve)(['name' => 'Harbour and Pine Packaging']);
    expect($r['vendor']->id)->toBe($this->harbor->id)->and($r['method'])->toBe('fuzzy')
        ->and($r['confidence'])->toBeGreaterThan(0.6)->toBeLessThan(1.0)
        ->and($r['candidates'][0]['name'])->toBe('Harbor & Pine Packaging Ltd');
});

it('4. asks the LLM to break a tie between close candidates', function () {
    mdVendor($this->company, 'Kestrel Industrial Supply Inc');
    $calls = new ArrayObject;
    app(LlmManager::class)->extend('fake', fn () => new class($calls) extends HeuristicLlm
    {
        public function __construct(private ArrayObject $calls) {}

        public function chooseVendor(array $extracted, array $candidates): array
        {
            $this->calls->append(compact('extracted', 'candidates'));

            return ['index' => array_search('Kestrel Industrial Parts LLC', array_column($candidates, 'name'), true), 'confidence' => 0.97, 'reason' => 'Same email domain.'];
        }
    });
    config(['extractiq.llm.default' => 'fake']);

    $r = ($this->resolve)(['name' => 'Kestrel Industrial', 'email' => 'billing@kestrel-parts.example']);

    expect(count($calls))->toBe(1)->and(count($calls[0]['candidates']))->toBeGreaterThanOrEqual(2)
        ->and($r['vendor']->id)->toBe($this->kestrel->id)->and($r['method'])->toBe('llm')
        ->and($r['confidence'])->toBe(0.9) // capped: a model's pick is never as sure as a tax ID
        ->and($r['reason'])->toBe('Same email domain.');
});

it('returns no vendor, with the near misses as candidates, when nothing is close enough', function () {
    mdVendor($this->company, 'Kestrel Industrial Supply Inc');
    $r = ($this->resolve)(['name' => 'Kestrel Industrial']); // the offline tie-break won't guess between these two

    expect($r['vendor'])->toBeNull()->and($r['method'])->toBe('none')->and($r['candidates'])->not->toBeEmpty();
    expect(($this->resolve)(['name' => 'Quillfeather Stationers'])['candidates'])->toBe([]);
});

it('stores the outcome on the document and the validation rule explains it', function () {
    $doc = Document::factory()->status(DocumentStatus::Extracted)->create(['company_id' => $this->company->id, 'vendor_name' => 'Harbour and Pine Packaging']);
    app(VendorResolver::class)->apply($doc)->save();
    App\Jobs\ValidateDocument::dispatchSync($doc->id);

    $doc->refresh();
    expect($doc->vendor_id)->toBe($this->harbor->id)->and($doc->vendor_match['method'])->toBe('fuzzy')
        ->and(collect($doc->rule_results)->firstWhere('rule', 'vendor_known')['status'])->toBe('warn')
        ->and(collect($doc->rule_results)->firstWhere('rule', 'vendor_known')['message'])->toContain('similar name');
});

it('lets a reviewer pick the vendor by hand and remembers the printed name as an alias', function () {
    Sanctum::actingAs(userWithRole('reviewer', $this->company));
    $doc = Document::factory()->status(DocumentStatus::NeedsReview)->create(['company_id' => $this->company->id, 'vendor_name' => 'KIP Fasteners Division']);

    $this->patchJson("/api/v1/documents/{$doc->id}/vendor", ['vendor_id' => $this->kestrel->id, 'remember_alias' => true])->assertOk()
        ->assertJsonPath('data.attributes.vendor_match.method', 'manual')
        ->assertJsonPath('data.relationships.vendor.data.id', (string) $this->kestrel->id);

    expect($this->kestrel->fresh()->aliases)->toContain('KIP Fasteners Division')
        ->and(($this->resolve)(['name' => 'KIP Fasteners Division'])['method'])->toBe('alias')
        ->and($doc->events()->where('step', 'vendor')->first()->meta['alias_added'])->toBe('KIP Fasteners Division');

    // Another company's vendor can't be picked.
    $other = mdVendor(App\Models\Company::factory()->create(), 'Elsewhere');
    $this->patchJson("/api/v1/documents/{$doc->id}/vendor", ['vendor_id' => $other->id])->assertStatus(422);
});
