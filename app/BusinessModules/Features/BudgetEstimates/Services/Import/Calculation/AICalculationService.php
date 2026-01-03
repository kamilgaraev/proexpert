<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Calculation;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered calculation and formula detection service
 * Automatically detects formulas and calculates missing values
 */
class AICalculationService
{
    public function __construct(
        private LLMProviderInterface $llmProvider
    ) {}

    /**
     * Проверяет и автоматически заполняет суммы для позиций
     * 
     * @param array $items Массив позиций сметы
     * @return array Обработанные позиции с заполненными/исправленными суммами
     */
    public function validateAndCalculate(array $items): array
    {
        $processed = [];
        $correctionsMade = 0;
        
        foreach ($items as $item) {
            $result = $this->validateItem($item);
            
            if ($result['corrected']) {
                $correctionsMade++;
                Log::info('[AICalculation] Corrected calculation', [
                    'item_name' => substr($item['item_name'] ?? '', 0, 50),
                    'original_total' => $item['current_total_amount'] ?? null,
                    'calculated_total' => $result['calculated_total'],
                    'difference' => $result['difference']
                ]);
            }
            
            $processed[] = $result['item'];
        }
        
        if ($correctionsMade > 0) {
            Log::info('[AICalculation] Calculation validation completed', [
                'total_items' => count($items),
                'corrections_made' => $correctionsMade
            ]);
        }
        
        return $processed;
    }

    /**
     * Проверяет отдельную позицию и исправляет расчеты если нужно
     */
    private function validateItem(array $item): array
    {
        $quantity = $item['quantity'] ?? 0;
        $unitPrice = $item['unit_price'] ?? 0;
        $currentTotal = $item['current_total_amount'] ?? null;
        
        // Пропускаем разделы
        if ($item['is_section'] ?? false) {
            return ['item' => $item, 'corrected' => false];
        }
        
        // Если нет данных для расчета, пропускаем
        if (empty($quantity) || empty($unitPrice)) {
            return ['item' => $item, 'corrected' => false];
        }
        
        // Рассчитываем ожидаемую сумму
        $calculatedTotal = round($quantity * $unitPrice, 2);
        
        // Проверяем текущую сумму
        if ($currentTotal === null || abs($currentTotal - $calculatedTotal) > 0.01) {
            // Сумма отсутствует или неверна - исправляем
            $item['current_total_amount'] = $calculatedTotal;
            $item['has_math_mismatch'] = ($currentTotal !== null && abs($currentTotal - $calculatedTotal) > 0.01);
            
            if ($item['has_math_mismatch']) {
                $item['warnings'][] = sprintf(
                    'Исправлена сумма: было %.2f, стало %.2f (кол-во %.2f × цена %.2f)',
                    $currentTotal,
                    $calculatedTotal,
                    $quantity,
                    $unitPrice
                );
            }
            
            return [
                'item' => $item,
                'corrected' => true,
                'calculated_total' => $calculatedTotal,
                'difference' => $currentTotal ? ($calculatedTotal - $currentTotal) : null
            ];
        }
        
        return ['item' => $item, 'corrected' => false];
    }

    /**
     * Определяет формулы в Excel файле с помощью AI
     */
    public function detectFormulas(array $headers, array $sampleRows): array
    {
        try {
            $prompt = $this->buildFormulaDetectionPrompt($headers, $sampleRows);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an Excel formula expert. Analyze data and identify calculation patterns. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1,
                'max_tokens' => 1500
            ]);

            $content = $response['content'] ?? '';
            $formulas = $this->parseFormulas($content);
            
            Log::info('[AICalculation] Formulas detected', [
                'formulas_count' => count($formulas)
            ]);
            
            return $formulas;

        } catch (\Exception $e) {
            Log::error('[AICalculation] Formula detection failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function buildFormulaDetectionPrompt(array $headers, array $sampleRows): string
    {
        $headersText = implode(', ', array_map(
            fn($col, $text) => "{$col}:'{$text}'",
            array_keys($headers),
            $headers
        ));

        $samplesText = '';
        foreach (array_slice($sampleRows, 0, 5) as $idx => $row) {
            $rowText = implode(', ', array_map(
                fn($col, $val) => "{$col}:" . (is_numeric($val) ? $val : "'{$val}'"),
                array_keys($row),
                array_values($row)
            ));
            $samplesText .= "Row" . ($idx + 1) . ": {$rowText}\n";
        }

        return <<<PROMPT
Analyze this Excel data and identify calculation formulas.

Headers: {$headersText}

Sample data:
{$samplesText}

Common construction estimate formulas:
- Total = Quantity × UnitPrice
- Total = Quantity × UnitPrice × Coefficient
- MaterialCost = Quantity × MaterialUnitPrice
- LaborCost = Hours × HourlyRate

Identify:
1. Which columns are calculated (not manually entered)?
2. What are the formulas?
3. Which columns are source data?

Return ONLY a JSON array:
[
  {
    "target_column": "F",
    "formula": "D * E",
    "description": "total = quantity × unit_price",
    "confidence": 0.95
  }
]
PROMPT;
    }

    private function parseFormulas(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return array_filter($data, fn($formula) => 
            isset($formula['target_column']) && 
            isset($formula['formula']) && 
            ($formula['confidence'] ?? 0) > 0.7
        );
    }

    private function extractJson(string $text): string
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);
        
        if (preg_match('/\[.*\]/s', $cleaned, $matches)) {
            return $matches[0];
        }
        
        return $cleaned;
    }
}
