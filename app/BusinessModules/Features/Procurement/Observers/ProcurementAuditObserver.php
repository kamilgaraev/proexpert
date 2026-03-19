<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Observers;

use App\BusinessModules\Features\Procurement\Models\ProcurementAuditLog;
use Illuminate\Database\Eloquent\Model;

class ProcurementAuditObserver
{
    private static array $pendingChanges = [];

    public function created(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'created',
            null,
            $model->toArray(),
            auth()->id(),
            'Запись создана'
        );
    }

    public function updating(Model $model): void
    {
        $dirty = $model->getDirty();

        if (empty($dirty)) {
            return;
        }

        $oldValues = [];

        foreach (array_keys($dirty) as $key) {
            $oldValues[$key] = $model->getOriginal($key);
        }

        self::$pendingChanges[spl_object_id($model)] = [
            'old' => $oldValues,
        ];
    }

    public function updated(Model $model): void
    {
        $key = spl_object_id($model);
        $changes = $model->getChanges();

        if (empty($changes)) {
            unset(self::$pendingChanges[$key]);
            return;
        }

        $oldValues = self::$pendingChanges[$key]['old'] ?? [];

        ProcurementAuditLog::logAction(
            $model,
            'updated',
            $oldValues,
            $changes,
            auth()->id(),
            'Запись обновлена'
        );

        unset(self::$pendingChanges[$key]);
    }

    public function deleted(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'deleted',
            $model->toArray(),
            null,
            auth()->id(),
            'Запись удалена'
        );
    }

    public function restored(Model $model): void
    {
        ProcurementAuditLog::logAction(
            $model,
            'restored',
            null,
            $model->toArray(),
            auth()->id(),
            'Запись восстановлена'
        );
    }
}
