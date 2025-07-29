<?php

namespace App\Observers;

use App\Models\CompletedWork;
use Illuminate\Support\Facades\Log;

class CompletedWorkObserver
{
    public function creating(CompletedWork $work): void
    {
        $this->calculateAmounts($work);
    }

    public function updating(CompletedWork $work): void
    {
        if ($work->wasChanged(['quantity', 'price', 'total_amount']) || $work->isDirty(['quantity', 'price', 'total_amount'])) {
            $this->calculateAmounts($work);
        }
    }

    public function saved(CompletedWork $work): void
    {
        // После сохранения пересчитываем на основе материалов, если они есть
        $this->recalculateFromMaterials($work);
    }

    protected function calculateAmounts(CompletedWork $work): void
    {
        try {
            // Если есть цена и количество, но нет общей суммы
            if ($work->price !== null && $work->quantity > 0 && $work->total_amount === null) {
                $work->total_amount = round($work->price * $work->quantity, 2);
            }
            
            // Если есть общая сумма и количество, но нет цены
            if ($work->total_amount !== null && $work->quantity > 0 && $work->price === null) {
                $work->price = round($work->total_amount / $work->quantity, 2);
            }

        } catch (\Exception $e) {
            Log::error('Failed to calculate CompletedWork amounts', [
                'work_id' => $work->id ?? 'new',
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function recalculateFromMaterials(CompletedWork $work): void
    {
        try {
            // Если нет цены и суммы, попытаемся рассчитать из материалов
            if ($work->price === null && $work->total_amount === null) {
                $materialsSum = 0;
                
                // Загружаем материалы если они не загружены
                if (!$work->relationLoaded('materials')) {
                    $work->load('materials');
                }

                foreach ($work->materials as $material) {
                    $pivotAmount = $material->pivot->total_amount ?? 0;
                    if ($pivotAmount <= 0) {
                        $quantity = $material->pivot->quantity ?? 0;
                        $unitPrice = $material->pivot->unit_price ?? 0;
                        $pivotAmount = $quantity * $unitPrice;
                    }
                    $materialsSum += $pivotAmount;
                }
                
                if ($materialsSum > 0) {
                    $work->update([
                        'total_amount' => round($materialsSum, 2),
                        'price' => $work->quantity > 0 ? round($materialsSum / $work->quantity, 2) : 0
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to recalculate CompletedWork from materials', [
                'work_id' => $work->id,
                'error' => $e->getMessage()
            ]);
        }
    }
} 