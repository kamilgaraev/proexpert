<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification;

use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies\RegexStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies\DictionaryStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies\AIStrategy;
use Illuminate\Support\Facades\Log;

class ItemClassificationService
{
    /** @var ClassificationStrategyInterface[] */
    private array $strategies;
    
    /** @var ClassificationStrategyInterface|null */
    private ?AIStrategy $aiStrategy = null;

    public function __construct(
        RegexStrategy $regexStrategy,
        DictionaryStrategy $dictionaryStrategy,
        AIStrategy $aiStrategy
    ) {
        // Local strategies (fast, free)
        $this->strategies = [
            $regexStrategy,
            $dictionaryStrategy,
        ];
        
        $this->aiStrategy = $aiStrategy;
    }

    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ClassificationResult
    {
        // For single item classification, we just use batch with 1 item
        // This ensures consistent logic
        $results = $this->classifyBatch([
            [
                'code' => $code,
                'name' => $name,
                'unit' => $unit,
                'price' => $price
            ]
        ]);
        
        return $results[0] ?? new ClassificationResult('work', 0.1, 'default_fallback');
    }

    /**
     * Classifies a batch of items using a hybrid approach (Local -> AI)
     * 
     * @param array $items Array of ['code' => ..., 'name' => ..., ...]
     * @return array<int, ClassificationResult>
     */
    public function classifyBatch(array $items): array
    {
        $finalResults = [];
        $needsAiClassification = [];

        // 1. Run Local Strategies (Regex, Dictionary)
        foreach ($items as $index => $item) {
            $bestLocalResult = null;
            
            foreach ($this->strategies as $strategy) {
                try {
                    $result = $strategy->classify(
                        $item['code'] ?? '',
                        $item['name'] ?? '',
                        $item['unit'] ?? null,
                        $item['price'] ?? null
                    );

                    if ($result) {
                        if ($bestLocalResult === null || $result->confidenceScore > $bestLocalResult->confidenceScore) {
                            $bestLocalResult = $result;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("[ItemClassificationService] Strategy {$strategy->getName()} failed", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Decision: Is local result good enough?
            if ($bestLocalResult && $bestLocalResult->confidenceScore >= 0.8) {
                $finalResults[$index] = $bestLocalResult;
            } else {
                // If not confident, mark for AI
                $needsAiClassification[$index] = $item;
                // Keep local result as fallback
                if ($bestLocalResult) {
                    $finalResults[$index] = $bestLocalResult;
                }
            }
        }

        // 2. Run AI Strategy for uncertain items
        if (!empty($needsAiClassification) && $this->aiStrategy) {
            Log::info('[ItemClassificationService] Running AI classification', [
                'items_count' => count($needsAiClassification),
                'items_needing_ai' => count($needsAiClassification)
            ]);
            
            try {
                // We batch process the subset that needs AI
                $aiResults = $this->aiStrategy->classifyBatch($needsAiClassification);
                
                $aiSuccessCount = 0;
                foreach ($aiResults as $index => $aiResult) {
                    // Update if AI result is available and (better or we didn't have one)
                    // Usually AI result is considered better for hard cases if it returns something
                    $finalResults[$index] = $aiResult;
                    $aiSuccessCount++;
                }
                
                Log::info('[ItemClassificationService] AI classification applied', [
                    'ai_results_received' => count($aiResults),
                    'ai_results_applied' => $aiSuccessCount
                ]);
                
            } catch (\Exception $e) {
                Log::error("[ItemClassificationService] AI Strategy failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // We implicitly fall back to local results or default
            }
        } elseif (!empty($needsAiClassification) && !$this->aiStrategy) {
            Log::warning('[ItemClassificationService] AI strategy not available but needed', [
                'items_needing_ai' => count($needsAiClassification)
            ]);
        }

        // 3. Fill missing with default
        foreach ($items as $index => $item) {
            if (!isset($finalResults[$index])) {
                $finalResults[$index] = new ClassificationResult('work', 0.1, 'default_fallback');
            }
        }

        return $finalResults;
    }
}
