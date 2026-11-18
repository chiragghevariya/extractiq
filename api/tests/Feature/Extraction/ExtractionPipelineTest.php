<?php

use Anthropic\Client;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Jobs\ExtractFields;
use App\Jobs\ValidateDocument;
use App\Models\Document;
use App\Models\Vendor;
use App\Services\Llm\Drivers\AnthropicLlm;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmManager;
use App\Services\Llm\SchemaRegistry;
use App\Services\Pipeline\EventRecorder;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** PSR-18 transport that records requests and replays canned Messages API responses in order. */
class FakeAnthropic implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $replies) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = json_decode((string) $request->getBody(), true);
        $reply = array_shift($this->replies);

        return new Response(200, ['content-type' => 'application/json'], json_encode([
            'id' => 'msg_'.count($this->requests), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($reply['json'])]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null, 'stop_details' => null,
            'usage' => ['input_tokens' => $reply['in'] ?? 2400, 'output_tokens' => $reply['out'] ?? 900],
        ]));
    }
}

function useClaude(array $replies): FakeAnthropic
{
    $transport = new FakeAnthropic($replies);
    $client = new Client(apiKey: 'test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);
    app(LlmManager::class)->extend('anthropic-fake', fn () => new AnthropicLlm(['model' => 'claude-opus-5-5', 'effort' => 'medium', 'max_tokens' => 16000], $client));
    config(['extractiq.llm.default' => 'anthropic-fake']);

    return $transport;
}

function runExtraction(Document $doc): Document
{
    Queue::fake();
    (new ExtractFields($doc->id))->handle(app(EventRecorder::class));
    (new ValidateDocument($doc->id))->handle(app(EventRecorder::class));

    return $doc->fresh();
}

beforeEach(function () {
    $this->company = demoCompany();
    foreach ([['Kestrel Industrial Parts LLC', '88-1234567'], ['Brightline Office Supply Co.', '77-7654321'], ['Nimbus Cloud Services Inc', '66-1112223']] as [$n, $t]) {
        Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => $n, 'tax_id' => $t]);
    }
});

it('fixture 1 (clean): validates, with sources pointing at the exact words and the token cost stored', function () {
    $claude = useClaude([['json' => fixtureReply('clean'), 'in' => 2400, 'out' => 900]]);
    $doc = runExtraction(fixtureDocument($this->company, 'clean'));

    expect($doc->status)->toBe(DocumentStatus::Validated)
        ->and($doc->document_number)->toBe('INV-K-2041')
        ->and($doc->po_number)->toBe('PO-1001')
        ->and($doc->total_minor)->toBe(61560)
        ->and($doc->vendor_id)->not->toBeNull()
        ->and($doc->lines)->toHaveCount(3)
        ->and($doc->schema_version)->toBe('invoice.v1')
        ->and($doc->llm_model)->toBe('claude-opus-5-5')
        ->and($doc->llm_input_tokens)->toBe(2400)
        ->and($doc->llm_output_tokens)->toBe(900)
        ->and($doc->llm_cost_usd)->toBe(0.0276);                 // 2400 × $4 + 900 × $20 per 1M

    // Sources: word ids resolve to the right OCR words.
    $words = $doc->pages()->first()->ocr['words'];
    $src = $doc->extraction['fields']['invoice_number']['source_words'];
    expect($words[(int) substr($src[0], 4)]['text'])->toBe('INV-K-2041')
        ->and($doc->extraction['fields']['total']['source_words'])->not->toBeEmpty();

    // Every rule recorded; none failed.
    expect(collect($doc->rule_results)->where('status', 'fail'))->toBeEmpty()
        ->and(collect($doc->rule_results)->pluck('rule'))->toContain('tax_id_format', 'line_math', 'total_math');

    // What went to Claude: structured output with the registry's schema, OCR rows with line ids, key/values.
    $req = $claude->requests[0];
    expect($req['model'])->toBe('claude-opus-5-5')
        ->and($req['output_config']['format']['schema'])->toBe(app(SchemaRegistry::class)->extraction('invoice'))
        ->and($req['messages'][0]['content'][0]['type'])->toBe('document')
        ->and($req['messages'][0]['content'][1]['text'])->toContain('[p1.l5] Invoice No: INV-K-2041')
        ->and($req['messages'][0]['content'][1]['text'])->toContain('## Key/value pairs detected by OCR');

    $event = $doc->events()->where('step', 'extract')->sole();
    expect($event->meta)->toMatchArray(['schema' => 'invoice.v1', 'input_tokens' => 2400, 'output_tokens' => 900, 'cost_usd' => 0.0276]);
});

it('fixture 2 (math error): the total_math rule fails and the document needs review', function () {
    useClaude([['json' => fixtureReply('math_error')]]);
    $doc = runExtraction(fixtureDocument($this->company, 'math_error'));

    expect($doc->status)->toBe(DocumentStatus::NeedsReview)
        ->and(collect($doc->rule_results)->firstWhere('rule', 'total_math'))->toMatchArray(['status' => 'fail'])
        ->and(collect($doc->validation['errors'])->pluck('code'))->toContain('total_math');
});

it('fixture 3 (scan): a confident model is capped by low OCR confidence on its source words', function () {
    useClaude([['json' => fixtureReply('low_confidence')]]);
    $doc = runExtraction(fixtureDocument($this->company, 'low_confidence'));

    $date = $doc->extraction['fields']['invoice_date'];
    expect($date['llm_confidence'])->toBeGreaterThanOrEqual(0.96)
        ->and($date['ocr_confidence'])->toBeLessThan(0.9)
        ->and($date['confidence'])->toBe($date['ocr_confidence'])
        ->and($doc->status)->toBe(DocumentStatus::NeedsReview)
        ->and(collect($doc->rule_results)->firstWhere('rule', 'confidence')['status'])->toBe('warn')
        ->and(collect($doc->validation['errors']))->toBeEmpty();
});

it('works the same through OpenAI with a strict JSON schema', function () {
    Http::fake(['api.openai.test/*' => Http::response([
        'model' => 'gpt-4.1-mini', 'usage' => ['prompt_tokens' => 3000, 'completion_tokens' => 1000],
        'choices' => [['message' => ['content' => json_encode(fixtureReply('clean')), 'refusal' => null]]],
    ])]);
    config(['extractiq.llm.default' => 'openai', 'extractiq.llm.drivers.openai' => ['api_key' => 'k', 'model' => 'gpt-4.1-mini', 'base_url' => 'https://api.openai.test/v1']]);
    app()->forgetInstance(LlmManager::class);

    $doc = runExtraction(fixtureDocument($this->company, 'clean'));

    expect($doc->status)->toBe(DocumentStatus::Validated)->and($doc->llm_provider)->toBe('openai')->and($doc->llm_cost_usd)->toBe(0.0028);
    Http::assertSent(fn ($r) => $r['response_format']['json_schema']['strict'] === true
        && $r['response_format']['json_schema']['schema'] === app(SchemaRegistry::class)->extraction('invoice'));
});

it('rejects a reply that does not match the schema', function () {
    $bad = fixtureReply('clean');
    unset($bad['total']);
    useClaude([['json' => $bad]]);
    $doc = fixtureDocument($this->company, 'clean');

    expect(fn () => (new ExtractFields($doc->id))->handle(app(EventRecorder::class)))->toThrow(LlmException::class, 'did not match invoice.v1');
    expect($doc->fresh()->status)->toBe(DocumentStatus::OcrDone);
});

it('asks the LLM to classify when the type is still unknown, then extracts with that schema', function () {
    $claude = useClaude([['json' => ['type' => 'invoice', 'confidence' => 0.97], 'in' => 300, 'out' => 20], ['json' => fixtureReply('clean')]]);
    $doc = runExtraction(fixtureDocument($this->company, 'clean', 'unknown'));

    expect($doc->type)->toBe(DocumentType::Invoice)->and($doc->type_source)->toBe('llm')
        ->and($claude->requests)->toHaveCount(2)
        ->and($claude->requests[0]['output_config']['effort'])->toBe('low')
        ->and($doc->llm_input_tokens)->toBe(2700);
});
