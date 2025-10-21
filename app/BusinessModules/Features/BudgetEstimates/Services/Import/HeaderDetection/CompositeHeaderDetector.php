<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class CompositeHeaderDetector implements HeaderDetectorInterface
{
    /**
     * @var HeaderDetectorInterface[]
     */
    private array $detectors;

    /**
     * @param HeaderDetectorInterface[] $detectors
     */
    public function __construct(array $detectors)
    {
        $this->detectors = $detectors;
    }

    public function getName(): string
    {
        return 'composite';
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function detectCandidates(Worksheet $sheet): array
    {
        $allCandidates = [];

        // Собираем кандидатов от всех детекторов
        foreach ($this->detectors as $detector) {
            $candidates = $detector->detectCandidates($sheet);
            
            foreach ($candidates as $candidate) {
                $allCandidates[] = $candidate;
            }
            
            Log::debug('[CompositeDetector] Detector results', [
                'detector' => $detector->getName(),
                'candidates_count' => count($candidates),
            ]);
        }

        // Группируем кандидатов по номеру строки
        $groupedByRow = [];
        foreach ($allCandidates as $candidate) {
            $row = $candidate['row'];
            
            if (!isset($groupedByRow[$row])) {
                $groupedByRow[$row] = [
                    'row' => $row,
                    'detectors' => [],
                    'detector_scores' => [],
                    'raw_values' => $candidate['raw_values'] ?? [],
                    'filled_columns' => $candidate['filled_columns'] ?? 0,
                ];
            }
            
            $groupedByRow[$row]['detectors'][] = $candidate['detector'];
            $groupedByRow[$row]['detector_scores'][$candidate['detector']] = $candidate;
        }

        // Преобразуем обратно в массив
        return array_values($groupedByRow);
    }

    public function scoreCandidate(array $candidate, array $context = []): float
    {
        $totalScore = 0.0;
        $totalWeight = 0.0;

        // Вычисляем взвешенную сумму оценок от всех детекторов
        foreach ($this->detectors as $detector) {
            $detectorName = $detector->getName();
            
            // Если этот детектор обнаружил данного кандидата
            if (isset($candidate['detector_scores'][$detectorName])) {
                $detectorCandidate = $candidate['detector_scores'][$detectorName];
                $score = $detector->scoreCandidate($detectorCandidate, $context);
                $weight = $detector->getWeight();
                
                $totalScore += $score * $weight;
                $totalWeight += $weight;
            }
        }

        // Нормализуем score
        $finalScore = $totalWeight > 0 ? $totalScore / $totalWeight : 0.0;

        // Бонус если кандидат обнаружен несколькими детекторами
        $detectorCount = count($candidate['detectors'] ?? []);
        if ($detectorCount > 1) {
            $consensusBonus = min(($detectorCount - 1) * 0.1, 0.3);
            $finalScore += $consensusBonus;
        }

        return min($finalScore, 1.0);
    }

    public function selectBest(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }

        // Оцениваем всех кандидатов
        $scoredCandidates = [];
        foreach ($candidates as $candidate) {
            $score = $this->scoreCandidate($candidate);
            $candidate['score'] = $score;
            $candidate['confidence'] = $score; // Для API
            $scoredCandidates[] = $candidate;
        }

        // Сортируем по score (убывание)
        usort($scoredCandidates, fn($a, $b) => $b['score'] <=> $a['score']);

        Log::info('[CompositeDetector] Scored candidates', [
            'total' => count($scoredCandidates),
            'top_3' => array_map(fn($c) => [
                'row' => $c['row'],
                'score' => round($c['score'], 3),
                'detectors' => $c['detectors'],
                'filled_columns' => $c['filled_columns'],
            ], array_slice($scoredCandidates, 0, 3)),
        ]);

        // Возвращаем топ-N кандидатов в специальном поле
        $best = $scoredCandidates[0];
        $best['all_candidates'] = array_slice($scoredCandidates, 0, 5); // Топ-5 для UI

        return $best;
    }
}

