<?php

namespace App\Services\Ocr\Drivers;

use App\Services\Ocr\Normalizer;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\OcrResult;
use Aws\Exception\AwsException;
use Aws\Textract\TextractClient;

/**
 * AWS Textract.
 *  - invoices/receipts → AnalyzeExpense (SummaryFields → key_values, LineItemGroups → tables)
 *  - POs, GRNs, unknown → AnalyzeDocument with TABLES + FORMS
 * Single-page files are analysed synchronously from bytes; multi-page PDFs use the async
 * Start…/Get… APIs against the original in S3, polling until done. The job id is reported
 * through OcrInput::onJobStarted so a retried job resumes polling instead of paying twice.
 */
class TextractOcr implements OcrProvider
{
    private ?TextractClient $client;

    public function __construct(private readonly array $config, ?TextractClient $client = null)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'textract';
    }

    public function recognize(OcrInput $input): OcrResult
    {
        $expense = $input->documentType === 'invoice';
        $async = $input->resumeJobId !== null || ($input->mimeType === 'application/pdf' && $input->pageCount > 1);

        try {
            if ($async) {
                [$api, $response] = $this->runAsync($input, $expense);
            } else {
                $api = $expense ? 'expense' : 'document';
                $doc = ['Document' => ['Bytes' => ($input->bytes)()]];
                $response = $expense
                    ? $this->client()->analyzeExpense($doc)->toArray()
                    : $this->client()->analyzeDocument($doc + ['FeatureTypes' => ['TABLES', 'FORMS']])->toArray();
            }
        } catch (AwsException $e) {
            $status = (int) $e->getStatusCode();
            $code = (string) $e->getAwsErrorCode();
            $retryable = $status >= 500 || in_array($code, ['ThrottlingException', 'ProvisionedThroughputExceededException', 'LimitExceededException', 'InternalServerError'], true);
            throw new OcrException("Textract $code: ".($e->getAwsErrorMessage() ?: $e->getMessage()), $retryable);
        }

        $pages = $api === 'expense' ? $this->fromExpense($response) : $this->fromBlocks($response['Blocks'] ?? []);
        if (! $pages) {
            throw new OcrException('Textract returned no text.', retryable: false);
        }

        return new OcrResult($pages, $this->name(), ['api' => $api === 'expense' ? 'AnalyzeExpense' : 'AnalyzeDocument', 'mode' => $async ? 'async' : 'sync']);
    }

    /** @return array{0: string, 1: array} */
    private function runAsync(OcrInput $input, bool $expense): array
    {
        if ($input->resumeJobId && str_contains($input->resumeJobId, ':')) {
            [$api, $jobId] = explode(':', $input->resumeJobId, 2);
        } else {
            if (! $input->s3) {
                throw new OcrException('Multi-page PDFs need the original in S3 for Textract async analysis (set DOCUMENTS_DISK=s3).', retryable: false);
            }
            $location = ['DocumentLocation' => ['S3Object' => ['Bucket' => $input->s3['bucket'], 'Name' => $input->s3['key']]]];
            $api = $expense ? 'expense' : 'document';
            $jobId = $expense
                ? $this->client()->startExpenseAnalysis($location)['JobId']
                : $this->client()->startDocumentAnalysis($location + ['FeatureTypes' => ['TABLES', 'FORMS']])['JobId'];
            if ($input->onJobStarted) {
                ($input->onJobStarted)("$api:$jobId");
            }
        }

        $deadline = microtime(true) + (float) ($this->config['max_wait_seconds'] ?? 300);
        $interval = (float) ($this->config['poll_interval_seconds'] ?? 5);
        while (true) {
            $first = $this->getPage($api, $jobId, null);
            $status = $first['JobStatus'] ?? 'IN_PROGRESS';
            if ($status === 'SUCCEEDED' || $status === 'PARTIAL_SUCCESS') {
                break;
            }
            if ($status === 'FAILED') {
                throw new OcrException('Textract job failed: '.($first['StatusMessage'] ?? 'unknown reason'), retryable: false);
            }
            if (microtime(true) >= $deadline) {
                // Retryable: the next attempt resumes polling the same job.
                throw new OcrException("Textract job $jobId still running after ".($this->config['max_wait_seconds'] ?? 300).'s.');
            }
            if ($interval > 0) {
                usleep((int) ($interval * 1_000_000));
            }
        }

        // Collect every result page (NextToken pagination).
        $merged = $api === 'expense' ? ['ExpenseDocuments' => []] : ['Blocks' => []];
        $token = null;
        $chunk = $first;
        do {
            if ($api === 'expense') {
                array_push($merged['ExpenseDocuments'], ...($chunk['ExpenseDocuments'] ?? []));
            } else {
                array_push($merged['Blocks'], ...($chunk['Blocks'] ?? []));
            }
            $token = $chunk['NextToken'] ?? null;
            if ($token) {
                $chunk = $this->getPage($api, $jobId, $token);
            }
        } while ($token);

        return [$api, $merged];
    }

    private function getPage(string $api, string $jobId, ?string $token): array
    {
        $args = ['JobId' => $jobId, 'MaxResults' => 1000] + ($token ? ['NextToken' => $token] : []);

        return $api === 'expense'
            ? $this->client()->getExpenseAnalysis($args)->toArray()
            : $this->client()->getDocumentAnalysis($args)->toArray();
    }

    /** AnalyzeDocument / DetectDocumentText blocks → pages. @return list<OcrPage> */
    public function fromBlocks(array $blocks, array $extraByPage = []): array
    {
        $byId = [];
        $byPage = [];
        foreach ($blocks as $b) {
            $byId[$b['Id']] = $b;
            $byPage[(int) ($b['Page'] ?? 1)][] = $b;
        }
        ksort($byPage);

        $pages = [];
        foreach ($byPage as $n => $pageBlocks) {
            $words = [];
            $lines = [];
            $kvs = [];
            $tables = [];
            foreach ($pageBlocks as $b) {
                switch ($b['BlockType']) {
                    case 'WORD':
                        $words[] = ['text' => $b['Text'] ?? '', 'bbox' => $this->box($b), 'confidence' => $this->conf($b)];
                        break;
                    case 'LINE':
                        $lines[] = ['text' => $b['Text'] ?? '', 'bbox' => $this->box($b), 'confidence' => $this->conf($b)];
                        break;
                    case 'KEY_VALUE_SET':
                        if (in_array('KEY', $b['EntityTypes'] ?? [], true)) {
                            $valueId = $this->related($b, 'VALUE')[0] ?? null;
                            $value = $valueId ? ($byId[$valueId] ?? null) : null;
                            $kvs[] = [
                                'key' => rtrim($this->childText($b, $byId), ' :'),
                                'value' => $value ? $this->childText($value, $byId) : '',
                                'confidence' => round(min($this->conf($b), $value ? $this->conf($value) : 1.0), 4),
                                'key_bbox' => $this->box($b),
                                'value_bbox' => $value ? $this->box($value) : null,
                            ];
                        }
                        break;
                    case 'TABLE':
                        $cells = [];
                        foreach ($this->related($b, 'CHILD') as $cellId) {
                            $cell = $byId[$cellId] ?? null;
                            if ($cell && $cell['BlockType'] === 'CELL') {
                                $cells[(int) $cell['RowIndex']][(int) $cell['ColumnIndex']] = $this->childText($cell, $byId);
                            }
                        }
                        ksort($cells);
                        $cols = $cells ? max(array_map(fn ($r) => max(array_keys($r)), $cells)) : 0;
                        $rows = array_values(array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', range(1, max(1, $cols))), $cells));
                        $tables[] = ['bbox' => $this->box($b), 'confidence' => $this->conf($b), 'rows' => $rows];
                        break;
                }
            }
            usort($lines, fn ($a, $b) => [round($a['bbox'][1], 3), $a['bbox'][0]] <=> [round($b['bbox'][1], 3), $b['bbox'][0]]);
            $pages[] = new OcrPage(
                number: $n,
                words: $words,
                lines: $lines,
                keyValues: array_merge($kvs, $extraByPage[$n]['key_values'] ?? []),
                tables: array_merge($tables, $extraByPage[$n]['tables'] ?? []),
                raw: ['format' => 'textract-blocks', 'blocks' => $pageBlocks] + (isset($extraByPage[$n]['raw']) ? ['expense' => $extraByPage[$n]['raw']] : []),
                plainText: Normalizer::rowsText($lines),
            );
        }

        return $pages;
    }

    /** AnalyzeExpense → pages: Blocks give words/lines; SummaryFields → key_values; LineItemGroups → tables. @return list<OcrPage> */
    public function fromExpense(array $response): array
    {
        $blocks = [];
        $extra = [];
        foreach ($response['ExpenseDocuments'] ?? [] as $doc) {
            array_push($blocks, ...($doc['Blocks'] ?? []));
            foreach ($doc['SummaryFields'] ?? [] as $f) {
                $page = (int) ($f['PageNumber'] ?? 1);
                $extra[$page]['key_values'][] = [
                    'key' => $f['LabelDetection']['Text'] ?? $this->humanType($f['Type']['Text'] ?? ''),
                    'value' => $f['ValueDetection']['Text'] ?? '',
                    'type' => $f['Type']['Text'] ?? null, // Textract's normalised field (INVOICE_RECEIPT_ID, TOTAL, VENDOR_NAME, …)
                    'confidence' => round(($f['ValueDetection']['Confidence'] ?? 0) / 100, 4),
                    'key_bbox' => isset($f['LabelDetection']['Geometry']) ? $this->geom($f['LabelDetection']['Geometry']) : null,
                    'value_bbox' => isset($f['ValueDetection']['Geometry']) ? $this->geom($f['ValueDetection']['Geometry']) : null,
                ];
                $extra[$page]['raw']['summary_fields'][] = $f;
            }
            foreach ($doc['LineItemGroups'] ?? [] as $group) {
                $headers = [];
                $rows = [];
                $boxes = [];
                $page = 1;
                foreach ($group['LineItems'] ?? [] as $item) {
                    $row = [];
                    foreach ($item['LineItemExpenseFields'] ?? [] as $f) {
                        $type = $f['Type']['Text'] ?? 'OTHER';
                        if ($type === 'EXPENSE_ROW') {
                            continue;
                        }
                        $headers[$type] = true;
                        $row[$type] = $f['ValueDetection']['Text'] ?? '';
                        $page = (int) ($f['PageNumber'] ?? $page);
                        if (isset($f['ValueDetection']['Geometry'])) {
                            $boxes[] = $this->geom($f['ValueDetection']['Geometry']);
                        }
                    }
                    $rows[] = $row;
                }
                $cols = array_keys($headers);
                $extra[$page]['tables'][] = [
                    'bbox' => Normalizer::union($boxes),
                    'confidence' => 1.0,
                    'rows' => array_merge([array_map(fn ($c) => $this->humanType($c), $cols)], array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', $cols), $rows)),
                ];
                $extra[$page]['raw']['line_item_groups'][] = $group;
            }
        }

        return $this->fromBlocks($blocks, $extra);
    }

    private function box(array $b): array
    {
        return $this->geom($b['Geometry'] ?? []);
    }

    private function geom(array $g): array
    {
        $bb = $g['BoundingBox'] ?? ['Left' => 0, 'Top' => 0, 'Width' => 0, 'Height' => 0];

        return [round((float) $bb['Left'], 4), round((float) $bb['Top'], 4), round((float) $bb['Width'], 4), round((float) $bb['Height'], 4)];
    }

    private function conf(array $b): float
    {
        return round(((float) ($b['Confidence'] ?? 0)) / 100, 4);
    }

    /** @return list<string> */
    private function related(array $b, string $type): array
    {
        foreach ($b['Relationships'] ?? [] as $r) {
            if ($r['Type'] === $type) {
                return $r['Ids'];
            }
        }

        return [];
    }

    private function childText(array $b, array $byId): string
    {
        $parts = [];
        foreach ($this->related($b, 'CHILD') as $id) {
            $child = $byId[$id] ?? null;
            if ($child && $child['BlockType'] === 'WORD') {
                $parts[] = $child['Text'];
            } elseif ($child && $child['BlockType'] === 'SELECTION_ELEMENT') {
                $parts[] = ($child['SelectionStatus'] ?? '') === 'SELECTED' ? '[x]' : '[ ]';
            }
        }

        return trim(implode(' ', $parts));
    }

    private function humanType(string $type): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $type)));
    }

    private function client(): TextractClient
    {
        if (! $this->client) {
            if (empty($this->config['key'])) {
                throw new OcrException('AWS credentials are not configured for Textract.', retryable: false);
            }
            $this->client = new TextractClient([
                'version' => '2018-06-27',
                'region' => $this->config['region'],
                'credentials' => ['key' => $this->config['key'], 'secret' => $this->config['secret']],
            ]);
        }

        return $this->client;
    }
}
