<?php

namespace App\Services\EstimatePositionCatalog;

use App\Models\EstimatePositionPriceHistory;
use App\Models\EstimatePositionCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PriceHistoryService
{
    /**
     * Сохранить изменение цены в историю
     */
    public function trackPriceChange(
        int $catalogItemId,
        float $oldPrice,
        float $newPrice,
        int $userId,
        ?string $reason = null
    ): EstimatePositionPriceHistory {
        try {
            $history = EstimatePositionPriceHistory::create([
                'catalog_item_id' => $catalogItemId,
                'user_id' => $userId,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'change_reason' => $reason,
                'changed_at' => now(),
            ]);

            Log::info('estimate_position_price_history.tracked', [
                'catalog_item_id' => $catalogItemId,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'user_id' => $userId,
            ]);

            return $history;
        } catch (\Exception $e) {
            Log::error('estimate_position_price_history.track_failed', [
                'catalog_item_id' => $catalogItemId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Получить историю цен для позиции
     */
    public function getPriceHistory(
        int $catalogItemId,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null
    ): Collection {
        $query = EstimatePositionPriceHistory::where('catalog_item_id', $catalogItemId)
            ->with('user');

        if ($dateFrom) {
            $query->where('changed_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('changed_at', '<=', $dateTo);
        }

        return $query->orderBy('changed_at', 'desc')->get();
    }

    /**
     * Получить цену на определенную дату
     */
    public function getPriceAtDate(int $catalogItemId, Carbon $date): ?float
    {
        // Найти последнее изменение цены до указанной даты
        $history = EstimatePositionPriceHistory::where('catalog_item_id', $catalogItemId)
            ->where('changed_at', '<=', $date)
            ->orderBy('changed_at', 'desc')
            ->first();

        if ($history) {
            return $history->new_price;
        }

        // Если истории нет, вернуть текущую цену
        $catalogItem = EstimatePositionCatalog::find($catalogItemId);
        return $catalogItem ? $catalogItem->unit_price : null;
    }

    /**
     * Сравнить цены на две даты
     */
    public function comparePrice(int $catalogItemId, Carbon $date1, Carbon $date2): array
    {
        $price1 = $this->getPriceAtDate($catalogItemId, $date1);
        $price2 = $this->getPriceAtDate($catalogItemId, $date2);

        $difference = $price2 - $price1;
        $percentChange = $price1 != 0 ? (($difference / $price1) * 100) : 0;

        return [
            'date1' => $date1->toDateString(),
            'price1' => $price1,
            'date2' => $date2->toDateString(),
            'price2' => $price2,
            'difference' => $difference,
            'percent_change' => round($percentChange, 2),
        ];
    }

    /**
     * Получить статистику изменений цен для позиции
     */
    public function getPriceStatistics(int $catalogItemId): array
    {
        $history = $this->getPriceHistory($catalogItemId);

        if ($history->isEmpty()) {
            return [
                'total_changes' => 0,
                'increases' => 0,
                'decreases' => 0,
                'avg_change_percent' => 0,
                'max_price' => null,
                'min_price' => null,
            ];
        }

        $increases = $history->filter(fn($h) => $h->isPriceIncrease())->count();
        $decreases = $history->filter(fn($h) => $h->isPriceDecrease())->count();
        $avgChangePercent = $history->avg(fn($h) => $h->getPriceChangePercent());
        $maxPrice = $history->max('new_price');
        $minPrice = $history->min('new_price');

        return [
            'total_changes' => $history->count(),
            'increases' => $increases,
            'decreases' => $decreases,
            'avg_change_percent' => round($avgChangePercent, 2),
            'max_price' => $maxPrice,
            'min_price' => $minPrice,
        ];
    }
}

