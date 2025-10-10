<?php

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

interface LLMProviderInterface
{
    public function chat(array $messages, array $options = []): array;
    
    public function countTokens(string $text): int;
    
    public function isAvailable(): bool;
    
    public function getModel(): string;
}

