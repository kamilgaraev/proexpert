<?php

namespace App\BusinessModules\Features\Procurement\Observers;

use Illuminate\Database\Eloquent\Model;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditLog;

/**
 * Observer для автоматического логирования изменений
 * в моделях модуля закупок
 */
class ProcurementAuditObserver
{
    /**
     * При создании модели
     */
    public function created(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'created',
            null,
            $model->toArray(),
            auth()->id(),
            "Запись создана"
        );
    }

    /**
     * При обновлении модели
     */
    public function updated(Model $model): void
    {
        // Получаем только измененные атрибуты
        $dirty = $model->getDirty();
        
        if (empty($dirty)) {
            return;
        }

        $oldValues = [];
        $newValues = [];

        foreach ($dirty as $key => $newValue) {
            $oldValues[$key] = $model->getOriginal($key);
            $newValues[$key] = $newValue;
        }

        ProcurementAuditLog::logAction(
            $model,
            'updated',
            $oldValues,
            $newValues,
            auth()->id(),
            "Запись обновлена"
        );
    }

    /**
     * При удалении модели
     */
    public function deleted(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'deleted',
            $model->toArray(),
            null,
            auth()->id(),
            "Запись удалена"
        );
    }

    /**
     * При восстановлении модели (soft delete)
     */
    public function restored(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'restored',
            null,
            $model->toArray(),
            auth()->id(),
            "Запись восстановлена"
        );
    }
}

