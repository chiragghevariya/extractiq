<?php

namespace App\Services\Llm\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Services\Llm\ExtractionRequest;
use App\Services\Llm\ExtractionResult;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmProvider;
use App\Services\Llm\VendorChoice;
use App\Services\Llm\SchemaRegistry;

/**
 * Claude via the official Anthropic PHP SDK. The original PDF goes in as a document
 * block next to the structured OCR prompt; structured outputs (output_config.format =
 * json_schema) guarantee the reply matches the extraction schema. Model and effort come
 * from .env (ANTHROPIC_MODEL, ANTHROPIC_EFFORT).
 */
class AnthropicLlm implements LlmProvider
{
    public function __construct(private readonly array $config, private ?Client $client = null) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        $content = [];
        if ($request->fileBytes && $request->mimeType === 'application/pdf' && strlen($request->fileBytes) < 30 * 1024 * 1024) {
            $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($request->fileBytes)]];
        }
        $content[] = ['type' => 'text', 'text' => $request->prompt];

        [$json, $message] = $this->call($request->system, $content, $request->schema, $this->config['effort'] ?? 'medium', (int) ($this->config['max_tokens'] ?? 16000));

        return new ExtractionResult($json, $this->name(), $message->model, [
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
        ]);
    }

    public function classify(string $text): array
    {
        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'confidence'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [...SchemaRegistry::TYPES, 'unknown']],
                'confidence' => ['type' => 'number'],
            ],
        ];
        [$json, $message] = $this->call(
            'Classify this accounts-payable document as an invoice, purchase_order (an order sent to a supplier), goods_receipt (a delivery/receiving note) or unknown. Give your 0..1 confidence.',
            [['type' => 'text', 'text' => mb_substr($text, 0, 6000)]], $schema, 'low', 1024,
        );

        return ['type' => (string) $json['type'], 'confidence' => (float) $json['confidence'], 'usage' => [
            'input_tokens' => $message->usage->inputTokens ?? null, 'output_tokens' => $message->usage->outputTokens ?? null,
        ]];
    }

    public function chooseVendor(array $extracted, array $candidates): array
    {
        [$json] = $this->call(
            'You match supplier names on accounts-payable documents to a company vendor list. Choose the candidate that is the same legal entity as the document vendor (consider trading names, abbreviations, tax IDs and email domains), or null if none is. Give your 0..1 confidence and a one-sentence reason.',
            [['type' => 'text', 'text' => json_encode(['document_vendor' => $extracted, 'candidates' => $candidates], JSON_PRETTY_PRINT)]],
            [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['index', 'confidence', 'reason'],
            'properties' => [
                'index' => ['type' => ['integer', 'null'], 'description' => 'Index into the candidate list, or null if none is the same company'],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
        ], 'low', 1024,
        );

        return VendorChoice::sanitize($json, count($candidates));
    }

    /** @return array{0: array, 1: object} */
    private function call(string $system, array $content, array $schema, string $effort, int $maxTokens): array
    {
        try {
            $message = $this->client()->beta->messages->create(
                model: $this->config['model'],
                maxTokens: $maxTokens,
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                // Server-side fallback model if the primary declines on policy grounds.
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
            );
        } catch (APIStatusException $e) {
            $status = (int) $e->status;
            throw new LlmException('Anthropic API error: '.($e->getAPIMessage() ?? $e->getMessage()), retryable: $status === 429 || $status >= 500);
        } catch (APIConnectionException $e) {
            throw new LlmException('Anthropic connection error: '.$e->getMessage());
        }

        if ($message->stopReason === 'refusal') {
            throw new LlmException('Model declined to process this document.', retryable: false);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new LlmException('Extraction was truncated (max_tokens).', retryable: false);
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                if (is_array($json)) {
                    return [$json, $message];
                }
            }
        }
        throw new LlmException('Model did not return JSON.', retryable: false);
    }

    private function client(): Client
    {
        if (! $this->client) {
            if (empty($this->config['api_key'])) {
                throw new LlmException('ANTHROPIC_API_KEY is not set.', retryable: false);
            }
            $this->client = new Client(apiKey: $this->config['api_key']);
        }

        return $this->client;
    }
}
