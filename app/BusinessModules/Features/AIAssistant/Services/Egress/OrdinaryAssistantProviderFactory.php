<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\Services\Logging\LoggingService;

final readonly class OrdinaryAssistantProviderFactory
{
    public function __construct(private OrdinaryAssistantEgress $egress, private LoggingService $logging) {}

    public function make(?callable $handler = null): LLMProviderInterface
    {
        $client = $this->egress->transport()->client($handler);
        $provider = match (config('ai-assistant.llm.provider', 'timeweb')) {
            'timeweb' => new TimewebProvider($this->logging, $client),
            'openai' => new OpenAIProvider($this->logging, $client),
            default => throw new AssistantEgressDenied('unsupported_purpose'),
        };
        $this->egress->transport()->bind($provider);

        return $provider;
    }
}
