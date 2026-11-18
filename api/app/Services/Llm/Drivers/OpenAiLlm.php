<?php

namespace App\Services\Llm\Drivers;

use App\Services\Llm\ExtractionRequest;
use App\Services\Llm\ExtractionResult;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmProvider;
use App\Services\Llm\VendorChoice;
use App\Services\Llm\SchemaRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI Chat Completions with a strict json_schema response format. Model from OPENAI_MODEL. */
class OpenAiLlm implements LlmProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'openai';
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        [$json, $body] = $this->call($request->system, $request->prompt, str_replace('.', '_', $request->schemaId), $request->schema);

        return new ExtractionResult($json, $this->name(), $body['model'] ?? null, [
            'input_tokens' => $body['usage']['prompt_tokens'] ?? null,
            'output_tokens' => $body['usage']['completion_tokens'] ?? null,
        ]);
    }

    public function classify(string $text): array
    {
        [$json, $body] = $this->call(
            'Classify this accounts-payable document as invoice, purchase_order, goods_receipt or unknown, with your 0..1 confidence.',
            mb_substr($text, 0, 6000), 'classification',
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'confidence'], 'properties' => [
                'type' => ['type' => 'string', 'enum' => [...SchemaRegistry::TYPES, 'unknown']], 'confidence' => ['type' => 'number'],
            ]],
        );

        return ['type' => (string) $json['type'], 'confidence' => (float) $json['confidence'], 'usage' => [
            'input_tokens' => $body['usage']['prompt_tokens'] ?? null, 'output_tokens' => $body['usage']['completion_tokens'] ?? null,
        ]];
    }

    public function chooseVendor(array $extracted, array $candidates): array
    {
        [$json] = $this->call(
            'You match supplier names on accounts-payable documents to a company vendor list. Choose the candidate that is the same legal entity as the document vendor (consider trading names, abbreviations, tax IDs and email domains), or null if none is. Give your 0..1 confidence and a one-sentence reason.',
            json_encode(['document_vendor' => $extracted, 'candidates' => $candidates], JSON_PRETTY_PRINT), 'vendor_choice',
            [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['index', 'confidence', 'reason'],
            'properties' => [
                'index' => ['type' => ['integer', 'null'], 'description' => 'Index into the candidate list, or null if none is the same company'],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
        ],
        );

        return VendorChoice::sanitize($json, count($candidates));
    }

    /** @return array{0: array, 1: array} */
    private function call(string $system, string $user, string $schemaName, array $schema): array
    {
        if (empty($this->config['api_key'])) {
            throw new LlmException('OPENAI_API_KEY is not set.', retryable: false);
        }
        try {
            $response = Http::withToken($this->config['api_key'])->timeout(180)
                ->post(rtrim($this->config['base_url'], '/').'/chat/completions', [
                    'model' => $this->config['model'],
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema]],
                ]);
        } catch (ConnectionException $e) {
            throw new LlmException('OpenAI connection error: '.$e->getMessage());
        }
        if ($response->failed()) {
            $status = $response->status();
            throw new LlmException("OpenAI API error ($status): ".$response->json('error.message', 'unknown'), retryable: $status === 429 || $status >= 500);
        }
        if ($refusal = $response->json('choices.0.message.refusal')) {
            throw new LlmException("Model refused: $refusal", retryable: false);
        }
        $json = json_decode((string) $response->json('choices.0.message.content'), true);
        if (! is_array($json)) {
            throw new LlmException('Model did not return JSON.', retryable: false);
        }

        return [$json, (array) $response->json()];
    }
}
