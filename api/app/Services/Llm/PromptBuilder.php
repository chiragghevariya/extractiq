<?php

namespace App\Services\Llm;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\Ocr\Normalizer;

/**
 * Builds the extraction prompt: OCR text with its line/table structure and line ids,
 * the key/value pairs and tables OCR found, vendor-specific hints learned from past
 * corrections, and the target schema (enforced separately via structured output).
 */
class PromptBuilder
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /** @param list<array{field: string, hint: string, label: ?string}> $hints */
    public function build(Document $document, string $type, array $hints = []): ExtractionRequest
    {
        $pages = DocumentPage::withoutGlobalScopes()->where('document_id', $document->id)->orderBy('page_number')->get();

        $rows = [];
        $keyValues = [];
        $tables = [];
        foreach ($pages as $page) {
            $ocr = $page->ocr ?? [];
            if (! empty($ocr['lines'])) {
                array_push($rows, ...Normalizer::rows($page->page_number, $ocr['lines']));
            }
            foreach ($ocr['key_values'] ?? [] as $kv) {
                $keyValues[] = $kv + ['page' => $page->page_number];
            }
            foreach ($ocr['tables'] ?? [] as $t) {
                $tables[] = $t + ['page' => $page->page_number];
            }
        }
        // No structured OCR (older documents): fall back to plain text lines without ids.
        if (! $rows) {
            foreach (preg_split('/\R/', (string) $document->ocr_text) as $line) {
                if (trim($line) !== '') {
                    $rows[] = ['ids' => [], 'text' => trim($line)];
                }
            }
        }

        return new ExtractionRequest(
            type: $type,
            schemaId: $this->schemas->id($type),
            schema: $this->schemas->extraction($type),
            system: $this->system($type),
            prompt: $this->prompt($type, $rows, $keyValues, $tables, $hints),
            rows: $rows,
            keyValues: $keyValues,
            hints: $hints,
            fileBytes: $document->mime_type === 'application/pdf' ? $document->fileContents() : null,
            mimeType: $document->mime_type,
            ocrText: (string) $document->ocr_text,
        );
    }

    public function system(string $type): string
    {
        $label = str_replace('_', ' ', $type);

        return <<<TXT
        You extract data from accounts-payable documents into a fixed JSON schema. This document is a {$label}.

        For every field return:
        - value: exactly what the document says, normalised — dates as YYYY-MM-DD, amounts as decimal numbers in major units (no symbols or thousands separators), currency as an ISO 4217 code. Use null when the field is absent. Never invent values.
        - confidence: 0..1, your honest certainty. Lower it for illegible, ambiguous, inferred or conflicting values.
        - source: the ids of the OCR lines the value was read from (the [p1.l7]-style prefixes). Use an empty list if it isn't on the page.

        The vendor is the supplier, never the buyer ("Bill To" / "Ship To" is the buyer). Line items appear in document order; number them from 1.
        Vendor-specific hints come from people correcting earlier documents from the same supplier — follow them.
        TXT;
    }

    private function prompt(string $type, array $rows, array $keyValues, array $tables, array $hints): string
    {
        $out = ["Document type: $type (schema {$this->schemas->id($type)})", '', '## OCR text (one visual row per line; [ids] are OCR line ids)'];
        foreach ($rows as $r) {
            $out[] = ($r['ids'] ? '['.implode(',', $r['ids']).'] ' : '').$r['text'];
        }

        if ($keyValues) {
            $out[] = '';
            $out[] = '## Key/value pairs detected by OCR';
            foreach ($keyValues as $kv) {
                $out[] = "- {$kv['key']}: {$kv['value']}".(isset($kv['type']) && $kv['type'] ? " ({$kv['type']})" : '').sprintf(' [%.0f%%]', $kv['confidence'] * 100);
            }
        }

        foreach ($tables as $i => $t) {
            $out[] = '';
            $out[] = '## Table '.($i + 1)." (page {$t['page']})";
            foreach ($t['rows'] as $row) {
                $out[] = '| '.implode(' | ', array_map(fn ($c) => str_replace('|', '/', (string) $c), $row)).' |';
            }
        }

        if ($hints) {
            $out[] = '';
            $out[] = '## Vendor-specific hints (learned from earlier corrections)';
            foreach ($hints as $h) {
                $out[] = "- {$h['hint']}";
            }
        }

        $out[] = '';
        $out[] = 'Extract the fields defined by the schema.';

        return implode("\n", $out);
    }
}
