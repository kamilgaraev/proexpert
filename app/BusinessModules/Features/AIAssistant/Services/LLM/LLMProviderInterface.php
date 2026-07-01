<?php

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

interface LLMProviderInterface
{
    /**
     * @param array $messages
     * @param array $options Can include 'profile', 'tools', 'timeout', 'model' for routed providers
     */
    public function chat(array $messages, array $options = []): array;
    
    public function countTokens(string $text): int;
    
    public function isAvailable(): bool;
    
    public function getModel(): string;
}

