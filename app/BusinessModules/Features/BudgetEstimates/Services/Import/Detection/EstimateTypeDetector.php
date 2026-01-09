<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\ProhelperDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\GrandSmetaDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\RIKDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\FERDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\SmartSmetaDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors\CustomTableDetector;
use Illuminate\Support\Facades\Log;

/**
 * Главный детектор типов смет
 * 
 * Запускает все конкретные детекторы и выбирает лучший результат
 */
class EstimateTypeDetector
{
    /**
     * @var EstimateTypeDetectorInterface[]
     */
    private array $detectors;
    
    public function __construct()
    {
        // Порядок важен: Prohelper первый (highest priority), потом специфичные, потом generic
        $this->detectors = [
            new ProhelperDetector(), // Highest priority - native format
            new GrandSmetaDetector(),
            new RIKDetector(),
            new FERDetector(),
            new SmartSmetaDetector(),
            new CustomTableDetector(), // Fallback - последний
        ];
    }
    
    /**
     * Запустить все детекторы и получить результаты
     * 
     * @param mixed $content Содержимое файла
     * @return array [
     *   'best' => ['type' => string, 'confidence' => float, 'indicators' => array, 'description' => string],
     *   'all' => array // Все результаты, отсортированные по confidence
     * ]
     */
    public function detectAll($content): array
    {
        $results = [];
        
        Log::info('[EstimateTypeDetector] Starting detection', [
            'detectors_count' => count($this->detectors),
        ]);
        
        foreach ($this->detectors as $detector) {
            try {
                $result = $detector->detect($content);
                
                $results[] = [
                    'type' => $detector->getType(),
                    'confidence' => $result['confidence'],
                    'indicators' => $result['indicators'],
                    'description' => $detector->getDescription(),
                ];
                
                Log::debug('[EstimateTypeDetector] Detector result', [
                    'type' => $detector->getType(),
                    'confidence' => $result['confidence'],
                    'indicators_count' => count($result['indicators']),
                ]);
            } catch (\Exception $e) {
                Log::error('[EstimateTypeDetector] Detector failed', [
                    'type' => $detector->getType(),
                    'error' => $e->getMessage(),
                ]);
                
                // Добавляем с нулевым confidence
                $results[] = [
                    'type' => $detector->getType(),
                    'confidence' => 0,
                    'indicators' => [],
                    'description' => $detector->getDescription(),
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Сортируем по confidence (от большего к меньшему)
        usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        $best = $results[0] ?? [
            'type' => 'custom',
            'confidence' => 0,
            'indicators' => [],
            'description' => 'Произвольная таблица',
        ];
        
        Log::info('[EstimateTypeDetector] Detection completed', [
            'best_type' => $best['type'],
            'best_confidence' => $best['confidence'],
            'candidates_count' => count($results),
        ]);
        
        return [
            'best' => $best,
            'all' => $results,
        ];
    }
    
    /**
     * Определить тип сметы (упрощенный метод - только лучший результат)
     * 
     * @param mixed $content Содержимое файла
     * @return array ['type' => string, 'confidence' => float, 'indicators' => array, 'description' => string]
     */
    public function detect($content): array
    {
        $result = $this->detectAll($content);
        return $result['best'];
    }
    
    /**
     * Получить топ-N кандидатов
     * 
     * @param mixed $content Содержимое файла
     * @param int $limit Количество кандидатов
     * @return array
     */
    public function getTopCandidates($content, int $limit = 3): array
    {
        $result = $this->detectAll($content);
        return array_slice($result['all'], 0, $limit);
    }
}

