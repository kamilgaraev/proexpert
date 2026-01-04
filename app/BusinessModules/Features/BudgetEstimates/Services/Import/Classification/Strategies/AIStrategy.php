<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
            // 1. Calculate hashes and prepare lookup map
            $itemHashes = [];
            $hashToItemIndex = [];
            
            foreach ($items as $index => $item) {
                $name = mb_strtolower(trim($item['name'] ?? ''));
                $unit = mb_strtolower(trim($item['unit'] ?? ''));
                $hash = md5($name . '|' . $unit);
                
                $itemHashes[$index] = $hash;
                if (!isset($hashToItemIndex[$hash])) {
                    $hashToItemIndex[$hash] = [];
                }
                $hashToItemIndex[$hash][] = $index;
            }
            
            // 2. Lookup in Cache Table
            $uniqueHashes = array_keys($hashToItemIndex);
            $cachedResults = [];
            
            try {
                // Try catch to handle missing table if migration didn't run
                $cachedRows = DB::table('estimate_import_ai_cache')
                    ->whereIn('hash', $uniqueHashes)
                    ->get(['hash', 'result_type', 'confidence', 'source']);
                    
                foreach ($cachedRows as $row) {
                    $cachedResults[$row->hash] = $row;
                }
            } catch (\Exception $e) {
                Log::warning('[AIStrategy] Cache table lookup failed (maybe table missing)', ['error' => $e->getMessage()]);
                // Continue without cache
            }
            
            $finalResults = [];
            $itemsToProcess = [];
            $indicesToProcess = [];
            
            // 3. Separate Hits and Misses
            foreach ($items as $index => $item) {
                $hash = $itemHashes[$index];
                
                if (isset($cachedResults[$hash])) {
                    $row = $cachedResults[$hash];
                    $finalResults[$index] = new ClassificationResult(
                        $row->result_type,
                        (float)$row->confidence,
                        $row->source . '_cache'
                    );
                } else {
                    $itemsToProcess[$index] = $item;
                    $indicesToProcess[] = $index;
                }
            }
            
            Log::info('[AIStrategy] Cache stats', [
                'total' => count($items),
                'hits' => count($items) - count($itemsToProcess),
                'misses' => count($itemsToProcess)
            ]);
            
            if (empty($itemsToProcess)) {
                return $finalResults;
            }
            
            // 4. Process only missing items with AI
            // Re-index array for AI (0, 1, 2...)
            $aiBatch = array_values($itemsToProcess);
            
            // Prepare items for the prompt
            $itemsData = array_map(function ($item, $localIndex) {
                return sprintf(
                    "ID:%d | Code:%s | Name:%s | Unit:%s",
                    $localIndex,
                    $item['code'] ?? '',
                    $item['name'] ?? '',
                    $item['unit'] ?? ''
                );
            }, $aiBatch, array_keys($aiBatch));

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
                // Return what we have from cache
                return $finalResults; 
            }
            
            // Extract JSON from response
            $json = $this->extractJson($content);
            $classifications = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($classifications)) {
                Log::warning('[AIStrategy] Invalid JSON response', ['error' => json_last_error_msg()]);
                return $finalResults;
            }

            // 5. Save results to DB and add to FinalResults
            $newCacheEntries = [];
            $now = now();
            
            foreach ($classifications as $localId => $type) {
                if (!isset($indicesToProcess[$localId])) {
                    continue; // Should not happen
                }
                
                $originalIndex = $indicesToProcess[$localId];
                $item = $items[$originalIndex];
                
                if ($type === null || $type === '') {
                    continue;
                }
                
                $normalizedType = $this->normalizeType($type);
                $hash = $itemHashes[$originalIndex];
                
                // Add to results
                $finalResults[$originalIndex] = new ClassificationResult($normalizedType, 0.85, 'ai_yandex');
                
                // Prepare for DB insert (avoid duplicates in batch)
                if (!isset($newCacheEntries[$hash])) {
                    $newCacheEntries[$hash] = [
                        'hash' => $hash,
                        'input_name' => mb_substr($item['name'] ?? '', 0, 60000), // Text field but safe limit
                        'input_unit' => mb_substr($item['unit'] ?? '', 0, 255),
                        'result_type' => $normalizedType,
                        'confidence' => 0.85,
                        'source' => 'ai_yandex',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            
            // Bulk Insert
            if (!empty($newCacheEntries)) {
                try {
                    DB::table('estimate_import_ai_cache')->insertOrIgnore(array_values($newCacheEntries));
                    Log::info('[AIStrategy] Cached new results', ['count' => count($newCacheEntries)]);
                } catch (\Exception $e) {
                    Log::error('[AIStrategy] Failed to cache results', ['error' => $e->getMessage()]);
                }
            }
            
            return $finalResults;

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

    private function normalizeType(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'work';
        }
        
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
