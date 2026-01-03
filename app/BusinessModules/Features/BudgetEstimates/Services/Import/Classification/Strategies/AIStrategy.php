<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;
use Illuminate\Support\Facades\Log;

class AIStrategy implements ClassificationStrategyInterface
{
    public function __construct(
        private LLMProviderInterface $llmProvider
    ) {}

    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ?ClassificationResult
    {
        // For single item, we just wrap it in a batch call
        $results = $this->classifyBatch([
            ['code' => $code, 'name' => $name, 'unit' => $unit, 'price' => $price]
        ]);

        return $results[0] ?? null;
    }

    public function classifyBatch(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        try {
            // Prepare items for the prompt
            $itemsData = array_map(function ($item, $index) {
                return sprintf(
                    "ID:%d | Code:%s | Name:%s | Unit:%s",
                    $index,
                    $item['code'] ?? '',
                    $item['name'] ?? '',
                    $item['unit'] ?? ''
                );
            }, $items, array_keys($items));

            $prompt = "You are a construction expert. Classify the following estimate items into types: 'work' (Work/Labor), 'material' (Material), 'equipment' (Machine/Equipment), 'labor' (Pure Labor). \n" .
                      "Return a JSON object where keys are IDs and values are types. Example: {\"0\": \"work\", \"1\": \"material\"}. \n\n" .
                      "Items:\n" . implode("\n", $itemsData);

            $messages = [
                ['role' => 'system', 'content' => 'You are a precise classifier for construction estimates.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            // Calling the LLM provider directly
            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1, // Low temperature for deterministic results
                'max_tokens' => 2000
            ]);

            $content = $response['content'] ?? '';
            
            // Extract JSON from response
            $json = $this->extractJson($content);
            $classifications = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('[AIStrategy] Failed to parse JSON response', ['error' => json_last_error_msg(), 'content' => $content]);
                return [];
            }

            $results = [];
            foreach ($classifications as $id => $type) {
                if (isset($items[$id])) {
                    // Normalize type
                    $normalizedType = $this->normalizeType($type);
                    $results[$id] = new ClassificationResult($normalizedType, 0.85, 'ai_yandex');
                }
            }

            return $results;

        } catch (\Exception $e) {
            Log::error('[AIStrategy] Classification failed', ['error' => $e->getMessage()]);
            // Circuit breaker: return empty array, system continues with fallback
            return [];
        }
    }

    private function extractJson(string $text): string
    {
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            return $matches[0];
        }
        return $text;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'material', 'materials' => 'material',
            'machine', 'equipment', 'mechanism' => 'equipment',
            'labor', 'labour' => 'labor',
            default => 'work',
        };
    }

    public function getName(): string
    {
        return 'ai_yandex';
    }
}
