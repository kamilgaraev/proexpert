<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\BusinessModules\Addons\AIEstimates\DTOs\MatchedPositionDTO;
use App\Models\EstimatePositionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatalogMatchingService
{
    protected float $minConfidence;
    protected int $maxAlternatives;

    public function __construct()
    {
        $this->minConfidence = config('ai-estimates.catalog_matching.min_confidence', 0.6);
        $this->maxAlternatives = config('ai-estimates.catalog_matching.max_alternatives', 3);
    }

    public function matchAIItemsToCatalog(array $aiItems, int $organizationId): array
    {
        $matchedItems = [];

        foreach ($aiItems as $aiItem) {
            try {
                $matched = $this->findBestMatch($aiItem, $organizationId);
                
                if ($matched) {
                    $matchedItems[] = array_merge($aiItem, [
                        'matched_catalog' => $matched->toArray(),
                    ]);
                } else {
                    $matchedItems[] = array_merge($aiItem, [
                        'matched_catalog' => null,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[CatalogMatchingService] Failed to match item', [
                    'ai_item' => $aiItem,
                    'error' => $e->getMessage(),
                ]);
                
                $matchedItems[] = array_merge($aiItem, [
                    'matched_catalog' => null,
                ]);
            }
        }

        return $matchedItems;
    }

    protected function findBestMatch(array $aiItem, int $organizationId): ?MatchedPositionDTO
    {
        $description = $aiItem['description'] ?? '';
        $workType = $aiItem['work_type'] ?? '';

        if (empty($description)) {
            return null;
        }

        // Поиск в каталоге позиций
        $catalogItems = EstimatePositionCatalog::where('organization_id', $organizationId)
            ->where(function ($query) use ($description, $workType) {
                $query->where('name', 'like', "%{$description}%")
                      ->orWhere('name', 'like', "%{$workType}%");
            })
            ->limit(5)
            ->get();

        if ($catalogItems->isEmpty()) {
            return null;
        }

        // Выбираем лучшее совпадение (простая логика - можно улучшить)
        $bestMatch = $catalogItems->first();
        $confidence = $this->calculateConfidence($aiItem, $bestMatch);

        if ($confidence < $this->minConfidence) {
            return null;
        }

        return MatchedPositionDTO::fromCatalogItem($bestMatch, $confidence);
    }

    protected function calculateConfidence(array $aiItem, $catalogItem): float
    {
        // Простая логика расчета уверенности на основе совпадения слов
        $aiWords = array_map('mb_strtolower', explode(' ', $aiItem['description'] ?? ''));
        $catalogWords = array_map('mb_strtolower', explode(' ', $catalogItem->name));

        $matchedWords = count(array_intersect($aiWords, $catalogWords));
        $totalWords = max(count($aiWords), count($catalogWords));

        if ($totalWords === 0) {
            return 0.0;
        }

        $wordMatchConfidence = $matchedWords / $totalWords;
        
        // Учитываем уверенность AI
        $aiConfidence = $aiItem['confidence'] ?? 0.5;

        // Комбинированная уверенность
        return round(($wordMatchConfidence + $aiConfidence) / 2, 2);
    }
}
