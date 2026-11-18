<?php

namespace App\Services\Llm;

use App\Services\Settings\ProviderSettings;
use App\Services\Llm\Drivers\AnthropicLlm;
use App\Services\Llm\Drivers\HeuristicLlm;
use App\Services\Llm\Drivers\OpenAiLlm;
use Illuminate\Support\Manager;

/** @mixin LlmProvider */
class LlmManager extends Manager
{
    /** The driver this company chose in Settings (or the environment default). */
    public function forCompany(int $companyId): LlmProvider
    {
        return $this->driver(ProviderSettings::for($companyId)->llm);
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('extractiq.llm.default');
    }

    public function createAnthropicDriver(): LlmProvider
    {
        return new AnthropicLlm($this->config->get('extractiq.llm.drivers.anthropic'));
    }

    public function createOpenaiDriver(): LlmProvider
    {
        return new OpenAiLlm($this->config->get('extractiq.llm.drivers.openai'));
    }

    public function createHeuristicDriver(): LlmProvider
    {
        return new HeuristicLlm;
    }
}
