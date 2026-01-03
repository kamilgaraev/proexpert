<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Mapping;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered smart column mapping service
 * Analyzes Excel headers and sample data to determine column purposes
 */
class AIColumnMapper
{
    public function __construct(
        private LLMProviderInterface $llmProvider
    ) {}

    /**
     * Определяет назначение колонок с помощью AI
     * 
     * @param array $headers Заголовки колонок ['A' => 'Наименование', 'B' => 'Ед.изм', ...]
     * @param array $sampleRows Примеры данных [['A' => 'Работа 1', 'B' => 'м2', ...], ...]
     * @return array Маппинг ['code' => 'A', 'name' => 'B', ...] с confidence scores
     */
    public function mapColumns(array $headers, array $sampleRows = []): array
    {
        try {
            $prompt = $this->buildMappingPrompt($headers, $sampleRows);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert in construction estimate formats. Analyze Excel columns and map them to estimate fields. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1,
                'max_tokens' => 1500
            ]);

            $content = $response['content'] ?? '';
            $mapping = $this->parseMapping($content);
            
            Log::info('[AIColumnMapper] Column mapping completed', [
                'headers_count' => count($headers),
                'mapped_fields' => array_keys($mapping['fields'] ?? []),
                'confidence' => $mapping['overall_confidence'] ?? 0
            ]);
            
            return $mapping;

        } catch (\Exception $e) {
            Log::error('[AIColumnMapper] Mapping failed', ['error' => $e->getMessage()]);
            return [
                'fields' => [],
                'overall_confidence' => 0.0,
                'suggestions' => []
            ];
        }
    }

    /**
     * Расширенный анализ с предсказанием формул и расчетов
     */
    public function analyzeStructure(array $headers, array $sampleRows = []): array
    {
        try {
            $prompt = $this->buildStructureAnalysisPrompt($headers, $sampleRows);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert in construction estimate analysis. Identify columns, formulas, and calculations. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1,
                'max_tokens' => 2000
            ]);

            $content = $response['content'] ?? '';
            $analysis = $this->parseStructureAnalysis($content);
            
            Log::info('[AIColumnMapper] Structure analysis completed', [
                'detected_formulas' => count($analysis['formulas'] ?? []),
                'calculated_columns' => count($analysis['calculated_columns'] ?? [])
            ]);
            
            return $analysis;

        } catch (\Exception $e) {
            Log::error('[AIColumnMapper] Structure analysis failed', ['error' => $e->getMessage()]);
            return [
                'fields' => [],
                'formulas' => [],
                'calculated_columns' => [],
                'confidence' => 0.0
            ];
        }
    }

    private function buildMappingPrompt(array $headers, array $sampleRows): string
    {
        $headersText = '';
        foreach ($headers as $col => $headerText) {
            $headersText .= "Column {$col}: \"{$headerText}\"\n";
        }

        $samplesText = '';
        if (!empty($sampleRows)) {
            $samplesText = "\n\nSample data (first 3 rows):\n";
            foreach (array_slice($sampleRows, 0, 3) as $idx => $row) {
                $samplesText .= "Row " . ($idx + 1) . ":\n";
                foreach ($row as $col => $value) {
                    $samplesText .= "  {$col}: " . substr((string)$value, 0, 50) . "\n";
                }
            }
        }

        return <<<PROMPT
Analyze these Excel columns from a construction estimate and map them to standard fields.

Headers:
{$headersText}{$samplesText}

Standard estimate fields to map:
- section_number: Номер раздела, позиции (№ п/п, Номер)
- code: Код позиции, шифр (Код, Шифр, ТЕР)
- name: Наименование работ/материалов
- unit: Единица измерения (Ед.изм, ЕИ)
- quantity: Количество (Кол-во, Объем)
- unit_price: Цена за единицу (Цена, Стоимость за ед)
- total_price: Общая стоимость (Сумма, Итого, Всего)
- labor_cost: Трудозатраты
- material_cost: Стоимость материалов

Return ONLY a JSON object:
{
  "fields": {
    "code": {"column": "A", "confidence": 0.95},
    "name": {"column": "B", "confidence": 0.98},
    ...
  },
  "overall_confidence": 0.92,
  "suggestions": [
    "Column F might be a calculated field (total = quantity * price)"
  ]
}
PROMPT;
    }

    private function buildStructureAnalysisPrompt(array $headers, array $sampleRows): string
    {
        $headersText = implode(', ', array_map(
            fn($col, $text) => "{$col}:'{$text}'",
            array_keys($headers),
            $headers
        ));

        $samplesText = '';
        foreach (array_slice($sampleRows, 0, 5) as $idx => $row) {
            $rowText = implode(', ', array_map(
                fn($col, $val) => "{$col}:'{$val}'",
                array_keys($row),
                array_values($row)
            ));
            $samplesText .= "Row" . ($idx + 1) . ": {$rowText}\n";
        }

        return <<<PROMPT
Analyze this construction estimate structure. Identify columns, formulas, and calculations.

Headers: {$headersText}

Sample data:
{$samplesText}

Analyze:
1. Which columns contain calculated values (formulas)?
2. What are the calculation rules? (e.g., total = quantity × price)
3. Which columns are source data vs derived data?
4. Are there any inconsistencies or missing calculations?

Return ONLY a JSON object:
{
  "fields": {
    "quantity": {"column": "C", "type": "input", "confidence": 0.95},
    "unit_price": {"column": "D", "type": "input", "confidence": 0.90},
    "total_price": {"column": "E", "type": "calculated", "confidence": 0.98}
  },
  "formulas": [
    {
      "target_column": "E",
      "formula": "C * D",
      "description": "total = quantity × unit_price"
    }
  ],
  "calculated_columns": ["E", "F"],
  "confidence": 0.93
}
PROMPT;
    }

    private function parseMapping(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return ['fields' => [], 'overall_confidence' => 0.0, 'suggestions' => []];
        }

        return [
            'fields' => $data['fields'] ?? [],
            'overall_confidence' => (float)($data['overall_confidence'] ?? 0.0),
            'suggestions' => $data['suggestions'] ?? []
        ];
    }

    private function parseStructureAnalysis(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return ['fields' => [], 'formulas' => [], 'calculated_columns' => [], 'confidence' => 0.0];
        }

        return [
            'fields' => $data['fields'] ?? [],
            'formulas' => $data['formulas'] ?? [],
            'calculated_columns' => $data['calculated_columns'] ?? [],
            'confidence' => (float)($data['confidence'] ?? 0.0)
        ];
    }

    private function extractJson(string $text): string
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);
        
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $cleaned, $matches)) {
            return $matches[0];
        }
        
        return $cleaned;
    }
}
