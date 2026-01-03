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

            $prompt = "Classify the following construction estimate items into types.\n\n" .
                      "Types:\n" .
                      "- 'work': Labor/Work (монтаж, установка, укладка, устройство)\n" .
                      "- 'material': Materials (бетон, кирпич, арматура, краска)\n" .
                      "- 'equipment': Machinery/Equipment (экскаватор, кран, бетономешалка)\n" .
                      "- 'labor': Pure labor costs (трудозатраты)\n\n" .
                      "Items to classify:\n" . implode("\n", $itemsData) . "\n\n" .
                      "IMPORTANT: Return ONLY a valid JSON object, no markdown, no explanations.\n" .
                      "Format: {\"0\": \"work\", \"1\": \"material\", \"2\": \"equipment\"}";

            $messages = [
                ['role' => 'system', 'content' => 'You are a precise JSON-only classifier. Return only valid JSON objects without markdown formatting.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            // Calling the LLM provider directly
            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1, // Low temperature for deterministic results
                'max_tokens' => 2000
            ]);

            $content = $response['content'] ?? '';
            
            if (empty($content)) {
                Log::warning('[AIStrategy] Empty response from LLM');
                return [];
            }
            
            // Extract JSON from response
            $json = $this->extractJson($content);
            
            if (empty($json)) {
                Log::warning('[AIStrategy] No JSON found in response', ['content' => substr($content, 0, 200)]);
                return [];
            }
            
            $classifications = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('[AIStrategy] Failed to parse JSON response', [
                    'error' => json_last_error_msg(),
                    'json' => substr($json, 0, 500),
                    'original_content' => substr($content, 0, 200)
                ]);
                return [];
            }
            
            if (!is_array($classifications)) {
                Log::warning('[AIStrategy] JSON is not an array', ['type' => gettype($classifications)]);
                return [];
            }

            $results = [];
            $successCount = 0;
            $unknownCount = 0;
            
            foreach ($classifications as $id => $type) {
                if (isset($items[$id])) {
                    // Normalize type
                    $normalizedType = $this->normalizeType($type);
                    $results[$id] = new ClassificationResult($normalizedType, 0.85, 'ai_yandex');
                    $successCount++;
                } else {
                    $unknownCount++;
                }
            }
            
            Log::info('[AIStrategy] Classification completed', [
                'total_items' => count($items),
                'classified' => $successCount,
                'unknown_ids' => $unknownCount,
                'coverage' => count($items) > 0 ? round(($successCount / count($items)) * 100, 1) . '%' : '0%'
            ]);
            
            // If we didn't get results for all items, log it but don't fail
            if ($successCount < count($items)) {
                Log::debug('[AIStrategy] Partial classification', [
                    'missing_count' => count($items) - $successCount,
                    'missing_ids' => array_diff(array_keys($items), array_keys($results))
                ]);
            }

            return $results;

        } catch (\Exception $e) {
            Log::error('[AIStrategy] Classification failed', ['error' => $e->getMessage()]);
            // Circuit breaker: return empty array, system continues with fallback
            return [];
        }
    }

    /**
     * Extract and clean JSON from LLM response
     * Handles markdown code blocks and other formatting
     * 
     * @param string $text Raw LLM response
     * @return string Clean JSON string
     */
    private function extractJson(string $text): string
    {
        // Step 1: Remove markdown code blocks (```json ... ```)
        $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);
        
        // Step 2: Try to extract JSON object if there's surrounding text
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $cleaned, $matches)) {
            return $matches[0];
        }
        
        // Step 3: Return cleaned text as-is if no JSON pattern found
        return $cleaned;
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
