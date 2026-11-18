<?php

namespace App\Services\Llm\Drivers;

use App\Services\Llm\ExtractionRequest;
use App\Services\Llm\ExtractionResult;
use App\Services\Llm\LlmProvider;
use App\Models\Vendor;
use Carbon\Carbon;
use Throwable;

/**
 * Offline, deterministic extractor (no API key, CI, demo seed). Reads the OCR rows by
 * label regexes, cites the matching row's line ids as the source, and follows
 * vendor hints: a hint for a field adds its learned label (e.g. "Order Ref") to the
 * labels tried for that field. Knows English and German labels ("Rechnungsnummer",
 * "Gesamtbetrag", amounts like 1.234,56). Replies in exactly the same wrapped schema as the LLMs.
 */
class HeuristicLlm implements LlmProvider
{
    private const SKU = '([A-Z0-9]{2,}(?:[\-_][A-Z0-9]+)+|[A-Z]{1,}\d[A-Z0-9]*)';

    private const VALUE = [
        'id' => '([A-Z0-9][A-Z0-9\-\/\.]{1,})',
        'date' => '([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4}|[0-9]{1,2} [A-Za-z]{3,9} [0-9]{4}|[A-Za-z]{3,9} [0-9]{1,2},? [0-9]{4})',
        'amount' => '(?:[A-Z]{3}\s*|[$€£₹]\s*)?(-?[0-9][0-9.,]*)(?:\s*(?:EUR|€))?',
        'text' => '(.+?)',
        'iban' => '([A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?)',
    ];

    /** @var list<array{ids: list<string>, text: string}> */
    private array $rows = [];

    /** @var array<string, list<string>> */
    private array $hintLabels = [];

    public function name(): string
    {
        return 'heuristic';
    }

    public function classify(string $text): array
    {
        $title = mb_strtolower(mb_substr(ltrim($text), 0, 80));
        foreach (['goods_receipt' => '/goods\s+receiv|goods\s+receipt|delivery\s+note|\bgrn\b|lieferschein/', 'purchase_order' => '/purchase\s+order|bestellung/', 'invoice' => '/invoice|rechnung/'] as $type => $re) {
            if (preg_match($re, $title)) {
                return ['type' => $type, 'confidence' => 0.7];
            }
        }

        return ['type' => 'unknown', 'confidence' => 0.0];
    }

    /**
     * Offline tie-break: score each candidate on tax id, email domain and how many of the
     * document name's words it shares (names and aliases), and only answer when one clearly wins.
     */
    public function chooseVendor(array $extracted, array $candidates): array
    {
        $words = fn (?string $s) => array_values(array_filter(explode(' ', Vendor::normalize($s)), fn ($w) => strlen($w) > 1));
        $docWords = $words($extracted['name'] ?? null);
        $docTax = Vendor::taxKey($extracted['tax_id'] ?? null);
        $docDomain = strtolower((string) substr(strrchr((string) ($extracted['email'] ?? ''), '@') ?: '', 1));
        $scores = [];
        foreach ($candidates as $i => $c) {
            if ($docTax && $docTax === Vendor::taxKey($c['tax_id'] ?? null)) {
                return ['index' => $i, 'confidence' => 0.95, 'reason' => 'Same tax ID.'];
            }
            $best = 0.0;
            foreach ([$c['name'] ?? '', ...($c['aliases'] ?? [])] as $name) {
                $cw = $words($name);
                $shared = count(array_intersect($docWords, $cw));
                $best = max($best, $cw && $docWords ? $shared / max(count($docWords), count($cw)) : 0);
            }
            $domain = strtolower((string) substr(strrchr((string) ($c['email'] ?? ''), '@') ?: '', 1));
            $scores[$i] = $best + ($docDomain !== '' && $docDomain === $domain ? 0.5 : 0);
        }
        arsort($scores);
        $top = array_key_first($scores);
        $values = array_values($scores);
        if ($top === null || $values[0] < 0.5 || (isset($values[1]) && $values[0] - $values[1] < 0.2)) {
            return ['index' => null, 'confidence' => 0.0, 'reason' => 'No candidate clearly matches.'];
        }

        return ['index' => $top, 'confidence' => round(min(0.85, 0.5 + $values[0] / 3), 2), 'reason' => 'Most shared name words'.($values[0] >= 1.5 ? ' and the same email domain.' : '.')];
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        $this->rows = $request->rows ?: array_values(array_map(fn ($l) => ['ids' => [], 'text' => $l], array_filter(preg_split('/\R/', $request->ocrText))));
        $this->hintLabels = [];
        foreach ($request->hints as $h) {
            if (! empty($h['label'])) {
                $this->hintLabels[$h['field']][] = preg_quote($h['label'], '/');
            }
        }

        $reply = match ($request->type) {
            'purchase_order' => $this->purchaseOrder(),
            'goods_receipt' => $this->goodsReceipt(),
            default => $this->invoice(),
        };

        return new ExtractionResult($reply, $this->name(), 'regex-v2', ['input_tokens' => 0, 'output_tokens' => 0]);
    }

    private function invoice(): array
    {
        return [
            'vendor' => $this->vendor(['vendor', 'supplier', 'from', 'bill from', 'sold by', 'lieferant']),
            'invoice_number' => $this->field('invoice_number', ['invoice\s*(?:no\.?|number|#)', 'rechnungs\s*(?:nr\.?|nummer)'], 'id'),
            'invoice_date' => $this->field('invoice_date', ['invoice\s+date', 'rechnungsdatum', '(?<!due )date'], 'date'),
            'due_date' => $this->field('due_date', ['due\s+date', 'payment\s+due', 'f(?:ä|ae)llig\s+am', 'zahlbar\s+bis'], 'date'),
            'po_number' => $this->field('po_number', ['po\s*(?:no\.?|number|#)', 'purchase\s+order(?:\s*(?:no\.?|number|#))?', 'bestell\s*(?:nr\.?|nummer)'], 'id'),
            'currency' => $this->currency(),
        ] + $this->totals() + [
            'payment_terms' => $this->paymentTerms(),
            'bank' => [
                'account' => ($iban = $this->field('bank.account', ['iban'], 'iban', 0.9))['value'] !== null ? $iban : $this->field('bank.account', ['account\s*(?:no\.?|number|#)?'], 'id', 0.9),
                'swift' => $this->field('bank.swift', ['swift(?:\s*\/\s*bic)?', 'bic'], 'id', 0.9),
            ],
            'line_items' => $this->lines(true),
        ];
    }

    private function purchaseOrder(): array
    {
        return [
            'vendor' => $this->vendor(['supplier', 'vendor']),
            'po_number' => $this->field('po_number', ['po\s*(?:no\.?|number|#)', 'purchase\s+order\s*(?:no\.?|number|#)'], 'id'),
            'order_date' => $this->field('order_date', ['po\s+date', 'order\s+date', '(?<!delivery )date'], 'date'),
            'delivery_date' => $this->field('delivery_date', ['delivery\s+date', 'deliver\s+by'], 'date'),
            'ship_to' => $this->field('ship_to', ['ship\s+to', 'deliver\s+to'], 'text', 0.9),
            'currency' => $this->currency(),
        ] + $this->totals() + [
            'payment_terms' => $this->paymentTerms(),
            'line_items' => $this->lines(true),
        ];
    }

    private function goodsReceipt(): array
    {
        return [
            'vendor' => $this->vendor(['supplier', 'vendor', 'from']),
            'grn_number' => $this->field('grn_number', ['grn\s*(?:no\.?|number|#)', '(?:receipt|delivery)\s*(?:note\s*)?(?:no\.?|number|#)'], 'id'),
            'receipt_date' => $this->field('receipt_date', ['receipt\s+date', 'received\s+(?:on|date)', 'delivery\s+date'], 'date'),
            'po_number' => $this->field('po_number', ['po\s*(?:no\.?|number|#)', 'purchase\s+order(?:\s*(?:no\.?|number|#))?'], 'id'),
            'received_by' => $this->field('received_by', ['received\s+by'], 'text', 0.9),
            'line_items' => $this->lines(false),
        ];
    }

    private function vendor(array $labels): array
    {
        $name = $this->field('vendor.name', array_map(fn ($l) => preg_quote($l, '/'), $labels), 'text', 0.92);

        return [
            'name' => $name['value'] !== null ? $name : $this->letterhead(),
            'tax_id' => $this->field('vendor.tax_id', ['tax\s*id', 'vat(?:\s*(?:no\.?|number|reg(?:istration)?))?', 'ein', 'gstin?', 'ust-?id(?:nr)?\.?'], 'id', 0.93),
            'address' => $this->empty(),
            'email' => $this->findAnywhere('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', 0.8),
        ];
    }

    /**
     * No "Vendor:" label: most invoices only name the seller in the letterhead. The first
     * company-looking name (legal suffix) in the top rows, never one behind a label like "Bill to:".
     */
    private function letterhead(): array
    {
        $suffix = '(?:LLC|L\.L\.C\.|Inc\.?|Ltd\.?|Limited|GmbH|AG|SA|S\.A\.|PLC|Corp\.?|Co\.|Company|B\.V\.)';
        foreach (array_slice($this->rows, 0, 6) as $row) {
            foreach (preg_split('/\s{2,}/', $row['text']) ?: [] as $segment) {
                if (preg_match('/^([A-Z][\w&\'.,\- ]{2,80}\s'.$suffix.')$/u', trim($segment), $m)) {
                    return ['value' => $m[1], 'confidence' => 0.9, 'source' => $row['ids']];
                }
            }
        }

        return $this->empty();
    }

    private function totals(): array
    {
        return [
            'subtotal' => $this->field('subtotal', ['sub\s*-?total', 'zwischensumme', 'nettobetrag'], 'amount'),
            'tax_total' => $this->field('tax_total', ['(?:sales\s+)?tax(?:\s*\([^)]*\))?', 'vat(?:\s*\([^)]*\))?', 'gst(?:\s*\([^)]*\))?', '(?:mwst|ust)\.?(?:\s*\([^)]*\))?'], 'amount'),
            'shipping' => $this->field('shipping', ['shipping', 'freight', 'delivery\s+charge', 'versand(?:kosten)?'], 'amount'),
            'total' => $this->field('total', ['grand\s+total', 'total\s+due', 'amount\s+due', '(?<!sub)total', 'gesamtbetrag', 'rechnungsbetrag'], 'amount'),
        ];
    }

    private function currency(): array
    {
        $c = $this->field('currency', ['currency', 'w(?:ä|ae)hrung'], 'id');
        if ($c['value'] !== null) {
            $c['value'] = strtoupper((string) $c['value']);

            return $c;
        }
        foreach (['€' => 'EUR', '£' => 'GBP', '₹' => 'INR', '$' => 'USD'] as $sym => $code) {
            foreach ($this->rows as $r) {
                if (str_contains($r['text'], $sym)) {
                    return ['value' => $code, 'confidence' => 0.7, 'source' => $r['ids']];
                }
            }
        }

        return $this->empty();
    }

    private function paymentTerms(): array
    {
        $t = $this->field('payment_terms', ['payment\s+terms', 'terms', 'zahlungsbedingungen'], 'text', 0.9);

        return $t['value'] !== null ? $t : $this->findAnywhere('/\b(net\s*\d{1,3}(?:\s*days)?|due\s+on\s+receipt)\b/i', 0.8);
    }

    /**
     * First row matching any label (learned hint labels first): "Label: value" on that row.
     *
     * @return array{value: mixed, confidence: float, source: list<string>}
     */
    private function field(string $field, array $labels, string $kind, float $conf = 0.95): array
    {
        $tries = array_merge(array_map(fn ($l) => [$l, true], $this->hintLabels[$field] ?? []), array_map(fn ($l) => [$l, false], $labels));
        foreach ($tries as [$label, $fromHint]) {
            // Values other than free text may also end at ", " (e.g. "IBAN DE89 …, BIC COBADEFFXXX").
            $end = $kind === 'text' ? '(?:\s{2,}|$)' : '(?:\s{2,}|[,;]\s|$)';
            $re = '/(?:^|\s{2,}|\b)'.$label.'\s*[:#]?\s*'.self::VALUE[$kind].$end.'/iu';
            foreach ($this->rows as $row) {
                if (preg_match($re, $row['text'], $m)) {
                    $value = $this->normalise(trim($m[1]), $kind);
                    if ($value !== null && $value !== '') {
                        return ['value' => $value, 'confidence' => $fromHint ? min($conf, 0.93) : $conf, 'source' => $row['ids']];
                    }
                }
            }
        }

        return $this->empty();
    }

    private function findAnywhere(string $re, float $conf): array
    {
        foreach ($this->rows as $row) {
            if (preg_match($re, $row['text'], $m)) {
                return ['value' => trim($m[1]), 'confidence' => $conf, 'source' => $row['ids']];
            }
        }

        return $this->empty();
    }

    private function normalise(string $raw, string $kind): mixed
    {
        return match ($kind) {
            'amount' => $this->amount($raw),
            'date' => $this->date($raw),
            default => rtrim($raw, ' .,;'),
        };
    }

    private function amount(string $raw): ?float
    {
        $raw = rtrim($raw, '.,');
        // European format: "1.234,56" or "234,57" (comma decimals, dot thousands).
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d{2}$/', $raw)) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        }
        $v = str_replace(',', '', $raw);

        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private function date(string $raw): ?string
    {
        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Rows like "SKU-123  Description  10  12.50  125.00" (GRN: "SKU-123  Description  10"). */
    private function lines(bool $priced): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $text = trim(preg_replace('/\s+/', ' ', $row['text']));
            $money = '(\d{1,3}(?:,\d{3})*\.\d{2}|\d+\.\d{2}|\d{1,3}(?:\.\d{3})*,\d{2})'; // 1,234.56 or 1.234,56
            if ($priced && preg_match('/^'.self::SKU.'\s+(.+?)\s+(\d+(?:\.\d+)?)\s+'.$money.'\s+'.$money.'$/', $text, $m)) {
                $out[] = ['line_no' => count($out) + 1, 'sku' => $m[1], 'description' => $m[2], 'qty' => (float) $m[3], 'uom' => null,
                    'unit_price' => $this->amount($m[4]), 'tax_rate' => null, 'amount' => $this->amount($m[5]), 'confidence' => 0.92, 'source' => $row['ids']];
            } elseif (! $priced && preg_match('/^'.self::SKU.'\s+(.+?)\s+(\d+(?:\.\d+)?)$/', $text, $m)) {
                $out[] = ['line_no' => count($out) + 1, 'sku' => $m[1], 'description' => $m[2], 'qty' => (float) $m[3], 'uom' => null,
                    'qty_ordered' => null, 'notes' => null, 'confidence' => 0.92, 'source' => $row['ids']];
            }
        }

        return $out;
    }

    private function empty(): array
    {
        return ['value' => null, 'confidence' => 0.0, 'source' => []];
    }
}
