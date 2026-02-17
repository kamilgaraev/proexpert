<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AiMappingService
{
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly LLMProviderInterface $llmProvider
    ) {}

    /**
     * Detect mapping using AI analysis of headers and sample data.
     */
    public function detectMapping(array $headers, array $sampleRows): ?array
    {
        if (empty($headers)) {
            return null;
        }

        $cacheKey = 'estimate_ai_mapping:' . md5(json_encode($headers) . json_encode($sampleRows));
        if ($cached = Cache::get($cacheKey)) {
            Log::info('[AiMappingService] Returning cached mapping');
            return $cached;
        }

        try {
            Log::info('[AiMappingService] Running AI column detection');
            
            $prompt = $this->buildPrompt($headers, $sampleRows);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert in construction estimates and Excel data mapping. Your task is to map columns from an imported file to standard estimate fields.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1,
                'max_tokens' => 1000
            ]);

            $content = $response['content'] ?? '';
            if (empty($content)) {
                return null;
            }

            $mapping = $this->extractJson($content);
            if (!$mapping) {
                Log::warning('[AiMappingService] Failed to extract JSON from AI response', ['content' => $content]);
                return null;
            }

            Log::info('[AiMappingService] AI Mapping detected', ['mapping' => $mapping]);

            Cache::put($cacheKey, $mapping, self::CACHE_TTL);
            return $mapping;

        } catch (\Throwable $e) {
            Log::error('[AiMappingService] AI Mapping failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function buildPrompt(array $headers, array $sampleRows): string
    {
        $headersList = [];
        foreach ($headers as $index => $text) {
            $headersList[] = "Column $index: " . ($text ?: "(empty)");
        }
    
        $samplesData = [];
        // Increase to 20 rows as requested to find sections/subsections
        foreach (array_slice($sampleRows, 0, 20) as $rowIndex => $row) {
            $rowStr = [];
            foreach ($row as $colIndex => $val) {
                $rowStr[] = "C$colIndex: " . ($val ?? 'null');
            }
            $samplesData[] = "Row $rowIndex: " . implode(" | ", $rowStr);
        }
    
        return "Analyze the following Excel headers and first 20 rows from a construction estimate file.\n\n" .
               "HEADERS:\n" . implode("\n", $headersList) . "\n\n" .
               "DATA ROWS (first 20):\n" . implode("\n", $samplesData) . "\n\n" .
               "TASK 1: Map columns to target fields (use null if not found):\n" .
               "- 'code': Normative code (e.g. ФЕР01-01-001, шифр)\n" .
               "- 'name': Item description/name\n" .
               "- 'unit': Unit of measurement (e.g. м3, шт, т)\n" .
               "- 'quantity': Amount/Volume\n" .
               "- 'unit_price': Price per unit\n" .
               "- 'current_total_amount': Total price for row\n" .
               "- 'section_number': Row number or numbering in the estimate\n\n" .
               "TASK 2: Identify Section/Subsection pattern:\n" .
               "- 'section_columns': Array of column indices that contain section titles (e.g. [0, 1])\n" .
               "- 'section_keywords': Array of words indicating a section (e.g. [\"Раздел\", \"Глава\", \"ИТОГО по\"]) or null if generic\n\n" .
               "RULES:\n" .
               "1. Return ONLY a valid JSON object.\n" .
               "2. Be extremely precise with column indices.\n\n" .
               "RESPONSE FORMAT (JSON only):\n" .
               "{\n" .
               "  \"mapping\": {\n" .
               "    \"code\": 1,\n" .
               "    \"name\": 2,\n" .
               "    \"unit\": 3,\n" .
               "    \"quantity\": 4,\n" .
               "    \"unit_price\": 5,\n" .
               "    \"current_total_amount\": 8,\n" .
               "    \"section_number\": 0\n" .
               "  },\n" .
               "  \"section_hints\": {\n" .
               "    \"section_columns\": [0, 2],\n" .
               "    \"section_keywords\": [\"Раздел\", \"Подраздел\", \"ЭТАП\"]\n" .
               "  }\n" .
               "}";
    }

    private function extractJson(string $content): ?array
    {
        // Try to find JSON block
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $json = $matches[0];
            $data = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        return null;
    }
}
