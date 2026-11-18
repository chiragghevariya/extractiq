<?php

namespace App\Services\Pipeline;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentPage;
use App\Services\Llm\ExtractionResult;
use App\Services\Llm\FieldMap;
use App\Services\Llm\SchemaRegistry;
use App\Services\Llm\SourceResolver;
use App\Services\Matching\VendorResolver;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Maps a validated extraction reply onto the document: plain values to columns (money as
 * minor units, ISO dates), lines, vendor link — and keeps the full extraction with per-field
 * confidence and *source word ids* resolved from the OCR.
 *
 * A field's confidence is min(model confidence, OCR confidence of its source words), so a
 * value read from blurry words can't pass as certain.
 */
class ExtractionApplier
{
    public function __construct(private readonly SchemaRegistry $schemas, private readonly VendorResolver $vendors) {}

    public function apply(Document $doc, string $type, ExtractionResult $result, array $extra = []): void
    {
        [$data, $meta] = $this->schemas->unwrap($type, $result->reply);
        $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('page_number')->get();
        $resolver = new SourceResolver($pages);

        $fields = [];
        foreach ($meta as $path => $m) {
            $value = $this->valueAt($data, $path);
            $resolved = $resolver->resolve(is_array($value) ? null : $value, $m['source']);
            $ocr = $resolved['ocr_confidence'];
            $fields[$path] = [
                'confidence' => round($ocr !== null ? min($m['confidence'], $ocr) : $m['confidence'], 4),
                'llm_confidence' => round($m['confidence'], 4),
                'ocr_confidence' => $ocr,
                'source_lines' => $m['source'],
                'source_words' => $resolved['words'],
                'has_value' => $value !== null && $value !== '' && $value !== [],
            ];
        }
        // Line items: highlight the whole row; confidence also capped by OCR of the row's words.
        foreach ($data['line_items'] ?? [] as $i => $line) {
            $row = $resolver->resolve($line['sku'] ?? $line['description'] ?? null, $meta["line_items.$i"]['source'] ?? []);
            $fields["line_items.$i"]['source_words'] = $row['words'];
            if ($row['ocr_confidence'] !== null) {
                $fields["line_items.$i"]['ocr_confidence'] = $row['ocr_confidence'];
                $fields["line_items.$i"]['confidence'] = round(min($fields["line_items.$i"]['confidence'], $row['ocr_confidence']), 4);
            }
        }

        $col = fn (string $key) => ($p = FieldMap::for($type)[$key] ?? null) ? $this->valueAt($data, $p) : null;
        $currency = $this->currency($col('currency')) ?? $doc->company->base_currency ?? 'USD';

        DB::transaction(function () use ($doc, $type, $result, $data, $fields, $extra, $col, $currency) {
            $doc->fill([
                'type' => DocumentType::from($type),
                'llm_provider' => $result->provider,
                'llm_model' => $result->model,
                'schema_version' => $this->schemas->id($type),
                'extraction' => ['schema' => $this->schemas->id($type), 'data' => $data, 'fields' => $fields] + $extra,
                'confidence' => $this->overall($fields),
                'vendor_name' => $this->str($col('vendor_name')),
                'document_number' => $this->str($col('document_number')),
                'po_number' => $this->str($col('po_number')),
                'document_date' => $this->date($col('document_date')),
                'due_date' => $this->date($col('due_date')),
                'payment_terms' => $this->str($col('payment_terms')),
                'currency' => $currency,
                'subtotal_minor' => Money::toMinor($col('subtotal'), $currency),
                'tax_minor' => Money::toMinor($col('tax'), $currency),
                'shipping_minor' => Money::toMinor($col('shipping'), $currency),
                'total_minor' => Money::toMinor($col('total'), $currency),
            ]);
            $this->vendors->apply($doc);
            $doc->save();

            $this->replaceLines($doc, array_map(fn ($l) => [
                'sku' => $l['sku'] ?? null,
                'description' => $l['description'] ?? '',
                'quantity' => $l['qty'] ?? 0,
                'uom' => $l['uom'] ?? null,
                'unit_price' => $l['unit_price'] ?? null,
                'tax_rate' => $l['tax_rate'] ?? null,
                'amount' => $l['amount'] ?? null,
            ], $data['line_items'] ?? []), major: true);
        });
    }

    /** Overall = the weakest extracted field (what decides review). */
    private function overall(array $fields): float
    {
        $c = array_column(array_filter($fields, fn ($f) => $f['has_value'] ?? true), 'confidence');

        return $c ? round(min($c), 4) : 0.0;
    }

    private function valueAt(array $data, string $path): mixed
    {
        return data_get($data, $path);
    }

    /** Replace all lines. $major=true when prices are decimals (from an LLM), false when already minor units (from the API). */
    public function replaceLines(Document $doc, array $lines, bool $major = false): void
    {
        DocumentLine::withoutGlobalScopes()->where('document_id', $doc->id)->delete();
        $currency = $doc->currency ?? 'USD';
        foreach (array_values($lines) as $i => $l) {
            DocumentLine::withoutGlobalScopes()->create([
                'company_id' => $doc->company_id,
                'document_id' => $doc->id,
                'line_no' => $i + 1,
                'sku' => $this->str($l['sku'] ?? null),
                'description' => mb_substr((string) ($l['description'] ?? ''), 0, 255) ?: '—',
                'quantity' => (float) ($l['quantity'] ?? 0),
                'uom' => $this->str($l['uom'] ?? null),
                'unit_price_minor' => $major ? Money::toMinor($l['unit_price'] ?? null, $currency) : ($l['unit_price_minor'] ?? null),
                'tax_rate' => isset($l['tax_rate']) && is_numeric($l['tax_rate']) ? (float) $l['tax_rate'] : null,
                'amount_minor' => $major ? Money::toMinor($l['amount'] ?? null, $currency) : ($l['amount_minor'] ?? null),
            ]);
        }
    }

    private function str(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    private function date(mixed $v): ?string
    {
        if (! $v || ! is_string($v)) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function currency(mixed $v): ?string
    {
        $v = strtoupper(trim((string) $v));

        return preg_match('/^[A-Z]{3}$/', $v) ? $v : null;
    }
}
