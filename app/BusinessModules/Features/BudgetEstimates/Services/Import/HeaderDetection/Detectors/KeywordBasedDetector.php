<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection\AbstractHeaderDetector;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class KeywordBasedDetector extends AbstractHeaderDetector
{
    private array $columnKeywords;

    public function __construct(array $columnKeywords)
    {
        $this->columnKeywords = $columnKeywords;
        $this->weight = 1.0;
    }

    public function getName(): string
    {
        return 'keyword_based';
    }

    public function detectCandidates(Worksheet $sheet): array
    {
        $candidates = [];
        $maxRow = min($sheet->getHighestRow(), $this->maxRowsToScan);

        for ($row = 1; $row <= $maxRow; $row++) {
            $rowValues = $this->getRowValues($sheet, $row);

            if (empty($rowValues) || $this->isServiceInfo($rowValues)) {
                continue;
            }

            $matches = $this->countKeywordMatches($rowValues);

            // Минимум 3 совпадения для кандидата
            if ($matches['total'] >= 3) {
                $candidates[] = [
                    'row' => $row,
                    'detector' => $this->getName(),
                    'keyword_matches' => $matches['total'],
                    'unique_keywords' => $matches['unique'],
                    'matched_keywords' => $matches['keywords'],
                    'filled_columns' => count($rowValues),
                    'raw_values' => $rowValues,
                ];
            }
        }

        return $candidates;
    }

    public function scoreCandidate(array $candidate, array $context = []): float
    {
        $score = 0.0;

        // 1. Базовый балл за keyword matches (0-0.4)
        $keywordMatches = $candidate['keyword_matches'] ?? 0;
        $score += min($keywordMatches / 10, 0.4);

        // 2. Бонус за уникальность keywords (0-0.3)
        $uniqueKeywords = count($candidate['unique_keywords'] ?? []);
        $score += min($uniqueKeywords / 10, 0.3);

        // 3. Бонус за количество колонок (0-0.2)
        $filledColumns = $candidate['filled_columns'] ?? 0;
        $score += min($filledColumns / 20, 0.2);

        // 4. Бонус за позицию (0-0.1)
        $row = $candidate['row'] ?? 0;
        if ($row >= 20 && $row <= 40) {
            $score += 0.1;
        } elseif ($row >= 10 && $row < 20) {
            $score += 0.05;
        }

        return min($score, 1.0);
    }

    /**
     * Подсчитывает совпадения с ключевыми словами
     *
     * @param array $rowValues
     * @return array
     */
    private function countKeywordMatches(array $rowValues): array
    {
        $totalMatches = 0;
        $uniqueKeywords = [];
        $matchedKeywords = [];

        foreach ($rowValues as $col => $value) {
            $normalized = mb_strtolower($value);

            foreach ($this->columnKeywords as $field => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $keyword)) {
                        $totalMatches++;
                        $matchedKeywords[] = "$col:$keyword";
                        
                        if (!in_array($keyword, $uniqueKeywords)) {
                            $uniqueKeywords[] = $keyword;
                        }
                        
                        break 2; // Переходим к следующей колонке
                    }
                }
            }
        }

        return [
            'total' => $totalMatches,
            'unique' => $uniqueKeywords,
            'keywords' => $matchedKeywords,
        ];
    }
}

