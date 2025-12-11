<?php

namespace App\Observers;

use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionPriceHistory;
use Illuminate\Support\Facades\Auth;

class EstimatePositionCatalogObserver
{
    /**
     * Handle the EstimatePositionCatalog "updating" event.
     * Срабатывает перед обновлением модели
     */
    public function updating(EstimatePositionCatalog $catalogItem): void
    {
        // Проверить, изменилась ли цена
        if ($catalogItem->isDirty('unit_price')) {
            $oldPrice = $catalogItem->getOriginal('unit_price');
            $newPrice = $catalogItem->unit_price;

            // Сохранить историю изменения цены
            EstimatePositionPriceHistory::create([
                'catalog_item_id' => $catalogItem->id,
                'user_id' => Auth::id() ?? $catalogItem->created_by_user_id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'change_reason' => $catalogItem->getAttribute('price_change_reason') ?? 'Изменение цены',
                'changed_at' => now(),
            ]);
        }
    }

    /**
     * Handle the EstimatePositionCatalog "deleting" event.
     * Проверка перед удалением
     */
    public function deleting(EstimatePositionCatalog $catalogItem): bool
    {
        // Проверка отключена, чтобы разрешить Soft Delete используемых позиций
        // if (!$catalogItem->canBeDeleted()) {
        //     return false;
        // }

        return true;
    }

    /**
     * Handle the EstimatePositionCatalog "deleted" event.
     * Очистка связанных данных после удаления
     */
    public function deleted(EstimatePositionCatalog $catalogItem): void
    {
        // Удалить историю цен (каскадное удаление настроено в миграции)
        // Дополнительная очистка, если нужно
    }
}

