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
                return null;
            }

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
        foreach (array_slice($sampleRows, 0, 10) as $rowIndex => $row) {
            $rowStr = [];
            foreach ($row as $colIndex => $val) {
                $rowStr[] = "C$colIndex: " . ($val ?? 'null');
            }
            $samplesData[] = "Row $rowIndex: " . implode(" | ", $rowStr);
        }

        return "Analyze the following Excel headers and sample data from a construction estimate file.\n\n" .
               "HEADERS:\n" . implode("\n", $headersList) . "\n\n" .
               "SAMPLE DATA (10 rows):\n" . implode("\n", $samplesData) . "\n\n" .
               "MAP to these target fields (use null if not found):\n" .
               "- 'code': Normative code (e.g. ФЕР01-01-001, шифр)\n" .
               "- 'name': Item description/name\n" .
               "- 'unit': Unit of measurement (e.g. м3, шт, т)\n" .
               "- 'quantity': Amount/Volume\n" .
               "- 'unit_price': Price per unit (e.g. стоимость единицы)\n" .
               "- 'current_total_amount': Total price for row (e.g. всего, общая стоимость)\n" .
               "- 'section_number': Row number or numbering in the estimate\n\n" .
               "RULES:\n" .
               "1. Return ONLY a valid JSON object where keys are field names and values are column indices (integers).\n" .
               "2. If 'name' and 'unit' are in the same column, map both to that column index.\n" .
               "3. Be careful with 'code' vs 'section_number'. 'code' usually contains letters and dashes.\n\n" .
               "RESPONSE FORMAT (JSON only):\n" .
               "{\n" .
               "  \"code\": 1,\n" .
               "  \"name\": 2,\n" .
               "  \"unit\": 2,\n" .
               "  \"quantity\": 3,\n" .
               "  \"unit_price\": 4,\n" .
               "  \"current_total_amount\": 7,\n" .
               "  \"section_number\": 0\n" .
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
