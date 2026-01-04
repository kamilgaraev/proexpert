<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Context;

use SplStack;
use Illuminate\Support\Facades\Log;

class StackSectionContext
{
    /** @var SplStack */
    private SplStack $stack;
    
    // Храним последний "верхнеуровневый" раздел как fallback
    private ?int $lastRootSectionId = null;
    
    public function __construct()
    {
        $this->stack = new SplStack();
    }

    public function pushSection(int $sectionId, int $level): void
    {
        // Нормализация уровня (защита от < 1)
        $level = max(1, $level);
        
        if ($level === 1) {
            $this->lastRootSectionId = $sectionId;
        }

        // Если текущий уровень стека больше или равен новому уровню,
        // нужно "закрыть" предыдущие разделы (удалить их из стека)
        while (!$this->stack->isEmpty()) {
            $current = $this->stack->top();
            if ($current['level'] >= $level) {
                $this->stack->pop();
            } else {
                break;
            }
        }
        
        $this->stack->push([
            'id' => $sectionId,
            'level' => $level
        ]);
    }

    public function getCurrentSectionId(): ?int
    {
        if ($this->stack->isEmpty()) {
            // Fallback: если стек пуст (например, items перед первой секцией),
            // возвращаем последний известный корень или null
            return $this->lastRootSectionId;
        }
        
        return $this->stack->top()['id'];
    }

    public function getParentSectionId(int $level): ?int
    {
        // Нормализация уровня
        $level = max(1, $level);
        
        // Уровень 1 не имеет родителя (null)
        if ($level === 1) {
            return null;
        }

        // Ищем в стеке ближайшего родителя (уровень меньше текущего)
        foreach ($this->stack as $item) {
            if ($item['level'] < $level) {
                return $item['id'];
            }
        }
        
        // Если стек не пуст, но родитель с меньшим уровнем не найден (например, прыжок 1 -> 5, а в стеке пусто)
        // Это странная ситуация (сирота). Возвращаем последний корень как "приемного родителя"
        if ($this->lastRootSectionId) {
            Log::warning('[StackSectionContext] Orphan section detected, attaching to last root', [
                'level' => $level,
                'root_id' => $this->lastRootSectionId
            ]);
            return $this->lastRootSectionId;
        }
        
        return null;
    }
    
    public function reset(): void
    {
        $this->stack = new SplStack();
        $this->lastRootSectionId = null;
    }
}
