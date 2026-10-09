<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

interface LLMProviderInterface
{
    /**
     * @param array $messages
     * @param array $options
     */
    public function chat(array $messages, array $options = []): array;
    
    public function responses(array $input, array $options = []): array;

    public function countTokens(string $text): int;
    
    public function isAvailable(): bool;
    
    public function getModel(): string;
}
