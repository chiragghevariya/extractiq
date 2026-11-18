<?php

namespace App\Services\Matching;

use App\Models\Document;
use App\Models\Vendor;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmManager;
use App\Support\Trigram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Works out which master-data vendor a document is from, in order of trust:
 *   1. exact tax ID                         confidence 1.00
 *   2. exact name or alias (normalised)     confidence 0.98
 *   3. fuzzy name / alias (trigram)         confidence = similarity, when one candidate clearly wins
 *   4. LLM tie-break between close fuzzy candidates
 * Otherwise no vendor (with the near misses as candidates so a reviewer can pick).
 */
class VendorResolver
{
    public const FUZZY_ACCEPT = 0.6;   // a fuzzy winner needs at least this similarity…

    public const FUZZY_MARGIN = 0.15;  // …and this lead over the runner-up

    public const FUZZY_FLOOR = 0.3;    // below this a candidate isn't worth showing

    public function __construct(private readonly LlmManager $llm) {}

    /**
     * @param  array{name?: ?string, tax_id?: ?string, email?: ?string, address?: ?string}  $extracted
     * @return array{vendor: ?Vendor, method: string, confidence: float, candidates: list<array{id: string, name: string, score: float}>, reason?: string}
     */
    public function resolve(int $companyId, array $extracted): array
    {
        $vendors = fn () => Vendor::withoutGlobalScopes()->where('company_id', $companyId);

        if ($tax = Vendor::taxKey($extracted['tax_id'] ?? null)) {
            if ($v = $vendors()->where('tax_key', $tax)->first()) {
                return $this->found($v, 'tax_id', 1.0);
            }
        }

        $name = Vendor::normalize($extracted['name'] ?? null);
        if ($name === '') {
            return ['vendor' => null, 'method' => 'none', 'confidence' => 0.0, 'candidates' => []];
        }

        // Exact name or alias. alias_keys is a small JSON list, so filter in PHP after a cheap prefilter.
        if ($v = $vendors()->where('normalized_name', $name)->first()) {
            return $this->found($v, 'name', 0.98);
        }
        $aliased = $vendors()->whereNotNull('alias_keys')->get()->first(fn (Vendor $v) => in_array($name, $v->alias_keys ?? [], true));
        if ($aliased) {
            return $this->found($aliased, 'alias', 0.98);
        }

        $ranked = $this->fuzzy($companyId, $name);
        $candidates = $ranked->map(fn ($r) => ['id' => (string) $r['vendor']->id, 'name' => $r['vendor']->name, 'score' => $r['score']])->values()->all();
        $top = $ranked->first();
        $second = $ranked->get(1);

        if ($top && $top['score'] >= self::FUZZY_ACCEPT && (! $second || $top['score'] - $second['score'] >= self::FUZZY_MARGIN)) {
            return $this->found($top['vendor'], 'fuzzy', $top['score'], $candidates);
        }

        // Close call (or a weak single candidate): let the model decide between the top few.
        $close = $ranked->filter(fn ($r) => $r['score'] >= self::FUZZY_FLOOR)->take(3)->values();
        if ($close->isNotEmpty()) {
            try {
                $choice = $this->llm->forCompany($companyId)->chooseVendor(
                    array_intersect_key($extracted, array_flip(['name', 'tax_id', 'email', 'address'])),
                    $close->map(fn ($r) => ['name' => $r['vendor']->name, 'tax_id' => $r['vendor']->tax_id, 'email' => $r['vendor']->email, 'aliases' => $r['vendor']->aliases ?? []])->all(),
                );
                if ($choice['index'] !== null && $choice['confidence'] >= 0.5) {
                    return $this->found($close[$choice['index']]['vendor'], 'llm', min(0.9, $choice['confidence']), $candidates) + ['reason' => $choice['reason']];
                }
            } catch (LlmException $e) {
                Log::warning('Vendor tie-break failed', ['error' => $e->getMessage()]);
            }
        }

        return ['vendor' => null, 'method' => 'none', 'confidence' => 0.0, 'candidates' => $candidates];
    }

    /** Resolve a document and store the outcome on it (vendor_id + vendor_match). Manual picks are kept. */
    public function apply(Document $doc): Document
    {
        if (($doc->vendor_match['method'] ?? null) === 'manual' && $doc->vendor_id) {
            return $doc;
        }
        $extracted = [
            'name' => $doc->vendor_name,
            'tax_id' => data_get($doc->extraction, 'data.vendor.tax_id.value'),
            'email' => data_get($doc->extraction, 'data.vendor.email.value') ?? $doc->email_from,
            'address' => data_get($doc->extraction, 'data.vendor.address.value'),
        ];
        $r = $this->resolve($doc->company_id, $extracted);
        $doc->vendor_id = $r['vendor']?->id;
        $doc->vendor_match = [
            'method' => $r['method'], 'confidence' => $r['confidence'], 'candidates' => $r['candidates'],
            'extracted_name' => $doc->vendor_name, 'extracted_tax_id' => $extracted['tax_id'], 'reason' => $r['reason'] ?? null,
        ];

        return $doc;
    }

    /**
     * Vendors ranked by best trigram similarity of their name or any alias.
     *
     * @return Collection<int, array{vendor: Vendor, score: float}>
     */
    public function fuzzy(int $companyId, string $normalizedName, int $limit = 5): Collection
    {
        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                select v.id, max(similarity(n.name, ?)) as score
                from vendors v
                cross join lateral (
                    select v.normalized_name as name
                    union all
                    select jsonb_array_elements_text(coalesce(v.alias_keys, '[]'::jsonb))
                ) n
                where v.company_id = ?
                group by v.id
                having max(similarity(n.name, ?)) >= ?
                order by score desc
                limit ?
            SQL, [$normalizedName, $companyId, $normalizedName, self::FUZZY_FLOOR, $limit]);
            $byId = Vendor::withoutGlobalScopes()->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');

            $out = [];
            foreach ($rows as $r) {
                if ($v = $byId->get($r->id)) {
                    $out[] = ['vendor' => $v, 'score' => round((float) $r->score, 4)];
                }
            }

            return collect($out);
        }

        return Vendor::withoutGlobalScopes()->where('company_id', $companyId)->get()
            ->map(fn (Vendor $v) => ['vendor' => $v, 'score' => max(array_map(fn ($k) => Trigram::similarity($k, $normalizedName), $v->nameKeys()))])
            ->filter(fn ($r) => $r['score'] >= self::FUZZY_FLOOR)
            ->sortByDesc('score')->take($limit)->values();
    }

    private function found(Vendor $v, string $method, float $confidence, array $candidates = []): array
    {
        return ['vendor' => $v, 'method' => $method, 'confidence' => round($confidence, 4), 'candidates' => $candidates];
    }
}
