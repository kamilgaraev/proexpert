<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered section detection service
 * Analyzes row context to determine if it's a section header
 */
class AISectionDetector
{
    public function __construct(
        private LLMProviderInterface $llmProvider
    ) {}

    /**
     * Определяет является ли строка разделом с помощью AI
     * 
     * @param array $rowData Данные строки
     * @param array $context Контекст (предыдущие и следующие строки)
     * @return array ['is_section' => bool, 'section_name' => string|null, 'confidence' => float]
     */
    public function detectSection(array $rowData, array $context = []): array
    {
        try {
            $prompt = $this->buildSectionDetectionPrompt($rowData, $context);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert in construction estimate structure analysis. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.2,
                'max_tokens' => 500
            ]);

            $content = $response['content'] ?? '';
            $result = $this->parseResponse($content);
            
            Log::debug('[AISectionDetector] Section detection result', [
                'row_name' => $rowData['name'] ?? 'unknown',
                'is_section' => $result['is_section'],
                'confidence' => $result['confidence']
            ]);
            
            return $result;

        } catch (\Exception $e) {
            Log::error('[AISectionDetector] Detection failed', ['error' => $e->getMessage()]);
            return [
                'is_section' => false,
                'section_name' => null,
                'confidence' => 0.0
            ];
        }
    }

    /**
     * Batch detection for multiple rows
     */
    public function detectSectionsBatch(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        try {
            $prompt = $this->buildBatchSectionPrompt($rows);
            
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert in construction estimate structure. Analyze rows and identify section headers. Return only valid JSON array.'],
                ['role' => 'user', 'content' => $prompt]
            ];

            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.2,
                'max_tokens' => 2000
            ]);

            $content = $response['content'] ?? '';
            $results = $this->parseBatchResponse($content);
            
            Log::info('[AISectionDetector] Batch detection completed', [
                'total_rows' => count($rows),
                'sections_found' => count(array_filter($results, fn($r) => $r['is_section']))
            ]);
            
            return $results;

        } catch (\Exception $e) {
            Log::error('[AISectionDetector] Batch detection failed', ['error' => $e->getMessage()]);
            return array_fill(0, count($rows), [
                'is_section' => false,
                'section_name' => null,
                'confidence' => 0.0
            ]);
        }
    }

    private function buildSectionDetectionPrompt(array $rowData, array $context): string
    {
        $name = $rowData['name'] ?? '';
        $code = $rowData['code'] ?? '';
        $hasQuantity = !empty($rowData['quantity']);
        $hasPrice = !empty($rowData['unit_price']);
        
        // Style info
        $isBold = $rowData['style']['is_bold'] ?? false;
        $isItalic = $rowData['style']['is_italic'] ?? false;
        $indentLevel = $rowData['style']['indent'] ?? 0;
        $merged = $rowData['style']['is_merged'] ?? false;
        
        $styleInfo = [];
        if ($isBold) $styleInfo[] = 'BOLD';
        if ($isItalic) $styleInfo[] = 'ITALIC';
        if ($indentLevel > 0) $styleInfo[] = "INDENT:{$indentLevel}";
        if ($merged) $styleInfo[] = 'MERGED_CELLS';
        $styleStr = implode(', ', $styleInfo);
        
        $contextInfo = '';
        if (!empty($context['previous'])) {
            $contextInfo .= "\nPrevious row: " . ($context['previous']['name'] ?? 'unknown');
        }
        if (!empty($context['next'])) {
            $contextInfo .= "\nNext row: " . ($context['next']['name'] ?? 'unknown');
        }

        return <<<PROMPT
Analyze this row from a construction estimate and determine if it's a section header (раздел).

Row data:
- Code: {$code}
- Name: {$name}
- Has quantity: {$hasQuantity}
- Has price: {$hasPrice}
- Visual Style: {$styleStr}{$contextInfo}

Section headers typically:
- Have descriptive names (e.g., "Земляные работы", "Фундамент", "Кровля")
- Often have hierarchical numbers (1, 1.1, 2, etc.)
- Usually DON'T have quantity or price
- Are often BOLD or have colored background
- Can span multiple columns (MERGED)
- Group related work items below them

Return ONLY a JSON object:
{
  "is_section": true/false,
  "section_name": "cleaned section name" or null,
  "confidence": 0.0-1.0,
  "reasoning": "brief explanation"
}
PROMPT;
    }

    private function buildBatchSectionPrompt(array $rows): string
    {
        $rowsText = '';
        foreach ($rows as $index => $row) {
            $name = $row['name'] ?? '';
            $code = $row['code'] ?? '';
            $hasData = !empty($row['quantity']) || !empty($row['unit_price']);
            
            // Style info extraction
            $isBold = $row['style']['is_bold'] ?? false;
            $merged = $row['style']['is_merged'] ?? false;
            $styleTags = [];
            if ($isBold) $styleTags[] = 'BOLD';
            if ($merged) $styleTags[] = 'MERGED';
            $styleStr = !empty($styleTags) ? '[' . implode(',', $styleTags) . ']' : '';
            
            $rowsText .= sprintf(
                "ID:%d | Code:%s | Name:%s | HasData:%s | %s\n",
                $index,
                $code,
                $name,
                $hasData ? 'yes' : 'no',
                $styleStr
            );
        }

        return <<<PROMPT
Analyze these rows from a construction estimate. Identify which are section headers (разделы).

Rows:
{$rowsText}

Section headers typically:
- Descriptive names grouping work items ("Земляные работы", "Фундамент")
- Hierarchical numbers (1, 1.1, 2.3, etc.)
- NO quantity or price data
- Often BOLD or MERGED cells
- Keywords: "Раздел", "Глава", specific work types

Return ONLY a JSON array with one object per row:
[
  {"id": 0, "is_section": false, "section_name": null, "confidence": 0.9},
  {"id": 1, "is_section": true, "section_name": "Земляные работы", "confidence": 0.95},
  ...
]
PROMPT;
    }

    private function parseResponse(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return ['is_section' => false, 'section_name' => null, 'confidence' => 0.0];
        }

        return [
            'is_section' => $data['is_section'] ?? false,
            'section_name' => $data['section_name'] ?? null,
            'confidence' => (float)($data['confidence'] ?? 0.0)
        ];
    }

    private function parseBatchResponse(string $content): array
    {
        $json = $this->extractJson($content);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        $results = [];
        foreach ($data as $item) {
            $id = $item['id'] ?? count($results);
            $results[$id] = [
                'is_section' => $item['is_section'] ?? false,
                'section_name' => $item['section_name'] ?? null,
                'confidence' => (float)($item['confidence'] ?? 0.0)
            ];
        }

        return $results;
    }

    private function extractJson(string $text): string
    {
        // Remove markdown code blocks
        $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);
        
        // Extract JSON object or array
        if (preg_match('/[\[{].*[\]}]/s', $cleaned, $matches)) {
            return $matches[0];
        }
        
        return $cleaned;
    }
}
