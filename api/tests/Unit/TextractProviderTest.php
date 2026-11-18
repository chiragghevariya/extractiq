<?php

use App\Services\Ocr\Drivers\TextractOcr;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrInput;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\Textract\Exception\TextractException;
use Aws\Textract\TextractClient;

function textract(MockHandler $mock, array $cfg = []): TextractOcr
{
    $client = new TextractClient(['version' => '2018-06-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock, 'retries' => 0]);

    return new TextractOcr($cfg + ['poll_interval_seconds' => 0, 'max_wait_seconds' => 5], $client);
}

function geo(float $l, float $t, float $w = 0.1, float $h = 0.02): array
{
    return ['BoundingBox' => ['Left' => $l, 'Top' => $t, 'Width' => $w, 'Height' => $h]];
}

/** A small AnalyzeDocument page: a line, its words, one FORMS key/value and a 2×2 table. */
function documentBlocks(int $page = 1): array
{
    $p = fn ($id) => "p$page-$id";

    return [
        ['BlockType' => 'PAGE', 'Id' => $p('page'), 'Page' => $page, 'Geometry' => geo(0, 0, 1, 1)],
        ['BlockType' => 'LINE', 'Id' => $p('l1'), 'Page' => $page, 'Text' => 'PO Number: PO-77', 'Confidence' => 99.1, 'Geometry' => geo(0.1, 0.1, 0.3)],
        ['BlockType' => 'WORD', 'Id' => $p('w1'), 'Page' => $page, 'Text' => 'PO', 'Confidence' => 99.5, 'Geometry' => geo(0.1, 0.1, 0.03)],
        ['BlockType' => 'WORD', 'Id' => $p('w2'), 'Page' => $page, 'Text' => 'Number:', 'Confidence' => 98.0, 'Geometry' => geo(0.14, 0.1, 0.08)],
        ['BlockType' => 'WORD', 'Id' => $p('w3'), 'Page' => $page, 'Text' => 'PO-77', 'Confidence' => 87.25, 'Geometry' => geo(0.24, 0.1, 0.06)],
        ['BlockType' => 'KEY_VALUE_SET', 'Id' => $p('k'), 'Page' => $page, 'EntityTypes' => ['KEY'], 'Confidence' => 95, 'Geometry' => geo(0.1, 0.1, 0.12),
            'Relationships' => [['Type' => 'VALUE', 'Ids' => [$p('v')]], ['Type' => 'CHILD', 'Ids' => [$p('w1'), $p('w2')]]]],
        ['BlockType' => 'KEY_VALUE_SET', 'Id' => $p('v'), 'Page' => $page, 'EntityTypes' => ['VALUE'], 'Confidence' => 90, 'Geometry' => geo(0.24, 0.1, 0.06),
            'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('w3')]]]],
        ['BlockType' => 'WORD', 'Id' => $p('c1w'), 'Page' => $page, 'Text' => 'SKU', 'Confidence' => 99, 'Geometry' => geo(0.1, 0.3)],
        ['BlockType' => 'WORD', 'Id' => $p('c2w'), 'Page' => $page, 'Text' => 'Qty', 'Confidence' => 99, 'Geometry' => geo(0.5, 0.3)],
        ['BlockType' => 'WORD', 'Id' => $p('c3w'), 'Page' => $page, 'Text' => 'A-1', 'Confidence' => 99, 'Geometry' => geo(0.1, 0.33)],
        ['BlockType' => 'WORD', 'Id' => $p('c4w'), 'Page' => $page, 'Text' => '10', 'Confidence' => 99, 'Geometry' => geo(0.5, 0.33)],
        ['BlockType' => 'CELL', 'Id' => $p('c1'), 'Page' => $page, 'RowIndex' => 1, 'ColumnIndex' => 1, 'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('c1w')]]], 'Geometry' => geo(0.1, 0.3)],
        ['BlockType' => 'CELL', 'Id' => $p('c2'), 'Page' => $page, 'RowIndex' => 1, 'ColumnIndex' => 2, 'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('c2w')]]], 'Geometry' => geo(0.5, 0.3)],
        ['BlockType' => 'CELL', 'Id' => $p('c3'), 'Page' => $page, 'RowIndex' => 2, 'ColumnIndex' => 1, 'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('c3w')]]], 'Geometry' => geo(0.1, 0.33)],
        ['BlockType' => 'CELL', 'Id' => $p('c4'), 'Page' => $page, 'RowIndex' => 2, 'ColumnIndex' => 2, 'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('c4w')]]], 'Geometry' => geo(0.5, 0.33)],
        ['BlockType' => 'TABLE', 'Id' => $p('t'), 'Page' => $page, 'Confidence' => 97, 'Geometry' => geo(0.1, 0.3, 0.5, 0.06),
            'Relationships' => [['Type' => 'CHILD', 'Ids' => [$p('c1'), $p('c2'), $p('c3'), $p('c4')]]]],
    ];
}

it('uses AnalyzeDocument with TABLES and FORMS for purchase orders and normalises the blocks', function () {
    $mock = new MockHandler([new Result(['Blocks' => documentBlocks()])]);

    $r = textract($mock)->recognize(new OcrInput(fn () => 'png', 'image/png', 1, [], 'purchase_order'));

    $cmd = $mock->getLastCommand();
    expect($cmd->getName())->toBe('AnalyzeDocument')->and($cmd['FeatureTypes'])->toBe(['TABLES', 'FORMS'])
        ->and($r->meta)->toBe(['api' => 'AnalyzeDocument', 'mode' => 'sync']);
    $page = $r->pages[0]->normalised();
    expect($page['words'][2])->toBe(['text' => 'PO-77', 'bbox' => [0.24, 0.1, 0.06, 0.02], 'confidence' => 0.8725])
        ->and($page['lines'][0]['text'])->toBe('PO Number: PO-77')
        ->and($page['key_values'][0])->toMatchArray(['key' => 'PO Number', 'value' => 'PO-77', 'confidence' => 0.9, 'value_bbox' => [0.24, 0.1, 0.06, 0.02]])
        ->and($page['tables'][0]['rows'])->toBe([['SKU', 'Qty'], ['A-1', '10']])
        ->and($r->pages[0]->raw['format'])->toBe('textract-blocks');
});

it('uses AnalyzeExpense for invoices: summary fields become key/values and line items a table', function () {
    $mock = new MockHandler([new Result(['ExpenseDocuments' => [[
        'ExpenseIndex' => 1,
        'SummaryFields' => [
            ['Type' => ['Text' => 'INVOICE_RECEIPT_ID'], 'LabelDetection' => ['Text' => 'Invoice No:', 'Geometry' => geo(0.1, 0.1)], 'ValueDetection' => ['Text' => 'INV-1', 'Confidence' => 99.2, 'Geometry' => geo(0.25, 0.1)], 'PageNumber' => 1],
            ['Type' => ['Text' => 'TOTAL'], 'ValueDetection' => ['Text' => '$615.60', 'Confidence' => 97.0, 'Geometry' => geo(0.7, 0.8)], 'PageNumber' => 1],
        ],
        'LineItemGroups' => [['LineItemGroupIndex' => 1, 'LineItems' => [
            ['LineItemExpenseFields' => [['Type' => ['Text' => 'ITEM'], 'ValueDetection' => ['Text' => 'Hex bolt', 'Geometry' => geo(0.1, 0.4)], 'PageNumber' => 1], ['Type' => ['Text' => 'PRICE'], 'ValueDetection' => ['Text' => '210.00', 'Geometry' => geo(0.8, 0.4)], 'PageNumber' => 1]]],
            ['LineItemExpenseFields' => [['Type' => ['Text' => 'ITEM'], 'ValueDetection' => ['Text' => 'Flange nut', 'Geometry' => geo(0.1, 0.43)], 'PageNumber' => 1], ['Type' => ['Text' => 'PRICE'], 'ValueDetection' => ['Text' => '90.00', 'Geometry' => geo(0.8, 0.43)], 'PageNumber' => 1]]],
        ]]],
        'Blocks' => [
            ['BlockType' => 'LINE', 'Id' => 'l', 'Page' => 1, 'Text' => 'Invoice No: INV-1', 'Confidence' => 99, 'Geometry' => geo(0.1, 0.1, 0.3)],
            ['BlockType' => 'WORD', 'Id' => 'w', 'Page' => 1, 'Text' => 'INV-1', 'Confidence' => 99, 'Geometry' => geo(0.25, 0.1)],
        ],
    ]]])]);

    $r = textract($mock)->recognize(new OcrInput(fn () => 'pdf', 'application/pdf', 1, [], 'invoice'));

    expect($mock->getLastCommand()->getName())->toBe('AnalyzeExpense')->and($r->meta['api'])->toBe('AnalyzeExpense');
    $page = $r->pages[0]->normalised();
    expect($page['key_values'][0])->toMatchArray(['key' => 'Invoice No:', 'value' => 'INV-1', 'type' => 'INVOICE_RECEIPT_ID', 'confidence' => 0.992])
        ->and($page['key_values'][1])->toMatchArray(['key' => 'Total', 'type' => 'TOTAL', 'value' => '$615.60'])
        ->and($page['tables'][0]['rows'])->toBe([['Item', 'Price'], ['Hex bolt', '210.00'], ['Flange nut', '90.00']])
        ->and($r->pages[0]->raw['expense']['summary_fields'])->toHaveCount(2);
});

it('analyses multi-page PDFs asynchronously from S3, polling and following NextToken', function () {
    $mock = new MockHandler([
        new Result(['JobId' => 'job-123']),
        new Result(['JobStatus' => 'IN_PROGRESS']),
        new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => documentBlocks(1), 'NextToken' => 'more']),
        new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => documentBlocks(2)]),
    ]);
    $started = null;

    $r = textract($mock)->recognize(new OcrInput(fn () => 'pdf', 'application/pdf', 2, [], 'goods_receipt',
        s3: ['bucket' => 'docs', 'key' => 'demo/2026/11/x.pdf'], onJobStarted: function ($j) use (&$started) {
            $started = $j;
        }));

    expect($started)->toBe('document:job-123')
        ->and($r->meta)->toBe(['api' => 'AnalyzeDocument', 'mode' => 'async'])
        ->and($r->pageCount())->toBe(2)
        ->and($r->pages[1]->number)->toBe(2)
        ->and($r->pages[1]->keyValues[0]['value'])->toBe('PO-77');
});

it('sends the S3 location and feature types when starting an async job', function () {
    $mock = new MockHandler([new Result(['JobId' => 'j']), new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => documentBlocks()])]);
    $commands = [];
    $client = new TextractClient(['version' => '2018-06-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => function (Command $c, $req) use ($mock, &$commands) {
        $commands[] = $c;

        return $mock($c, $req);
    }]);

    (new TextractOcr(['poll_interval_seconds' => 0], $client))->recognize(new OcrInput(fn () => 'pdf', 'application/pdf', 3, [], 'unknown', s3: ['bucket' => 'docs-bucket', 'key' => 'k.pdf']));

    expect($commands[0]->getName())->toBe('StartDocumentAnalysis')
        ->and($commands[0]['DocumentLocation'])->toBe(['S3Object' => ['Bucket' => 'docs-bucket', 'Name' => 'k.pdf']])
        ->and($commands[0]['FeatureTypes'])->toBe(['TABLES', 'FORMS'])
        ->and($commands[1]->getName())->toBe('GetDocumentAnalysis');
});

it('resumes polling an existing job on retry instead of starting a new one', function () {
    $mock = new MockHandler([new Result(['JobStatus' => 'SUCCEEDED', 'ExpenseDocuments' => [['Blocks' => [['BlockType' => 'WORD', 'Id' => 'w', 'Text' => 'Hi', 'Confidence' => 90, 'Geometry' => geo(0, 0)]]]]])]);

    textract($mock)->recognize(new OcrInput(fn () => 'pdf', 'application/pdf', 4, [], 'invoice', resumeJobId: 'expense:job-9'));

    expect($mock->getLastCommand()->getName())->toBe('GetExpenseAnalysis')->and($mock->getLastCommand()['JobId'])->toBe('job-9');
});

it('times out retryably while a job is still running', function () {
    $mock = new MockHandler(array_fill(0, 20, new Result(['JobStatus' => 'IN_PROGRESS'])));

    try {
        textract($mock, ['max_wait_seconds' => 0])->recognize(new OcrInput(fn () => 'pdf', 'application/pdf', 2, [], 'unknown', resumeJobId: 'document:j'));
        $this->fail('expected exception');
    } catch (OcrException $e) {
        expect($e->retryable)->toBeTrue()->and($e->getMessage())->toContain('still running');
    }
});

it('maps failures: failed jobs and missing S3 are final, throttling is retryable', function () {
    $final = fn (callable $fn) => (function () use ($fn) {
        try {
            $fn();
        } catch (OcrException $e) {
            return $e->retryable;
        }

        return null;
    })();

    expect($final(fn () => textract(new MockHandler([new Result(['JobStatus' => 'FAILED', 'StatusMessage' => 'Unsupported document'])]))
        ->recognize(new OcrInput(fn () => 'x', 'application/pdf', 2, [], 'unknown', resumeJobId: 'document:j'))))->toBeFalse();

    expect($final(fn () => textract(new MockHandler([]))->recognize(new OcrInput(fn () => 'x', 'application/pdf', 5, [], 'unknown'))))->toBeFalse();

    $throttled = new MockHandler([fn (Command $c) => new TextractException('Slow down', $c, ['code' => 'ThrottlingException'])]);
    expect($final(fn () => textract($throttled)->recognize(new OcrInput(fn () => 'x', 'image/png'))))->toBeTrue();

    $badKeys = new MockHandler([fn (Command $c) => new TextractException('Bad keys', $c, ['code' => 'UnrecognizedClientException'])]);
    expect($final(fn () => textract($badKeys)->recognize(new OcrInput(fn () => 'x', 'image/png'))))->toBeFalse();
});
