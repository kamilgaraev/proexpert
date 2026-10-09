<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Services\Logging\LoggingService;
use App\Support\AI\TokenCounter;
use DomainException;

final class DeepSeekProvider implements LLMProviderInterface
{
    public function __construct(private readonly LoggingService $logging)
    {
    }

    public function chat(array $messages, array $options = []): array
    {
        throw new DomainException('ai_luna_provider_required');
    }

    public function responses(array $input, array $options = []): array
    {
        throw new DomainException('ai_luna_provider_required');
    }

    public function countTokens(string $text): int
    {
        return (new TokenCounter())->text($text);
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getModel(): string
    {
        return 'deepseek-chat';
    }
}
