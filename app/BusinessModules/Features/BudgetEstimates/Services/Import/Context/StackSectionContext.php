<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Context;

use SplStack;

class StackSectionContext
{
    /** @var SplStack */
    private SplStack $stack;
    
    public function __construct()
    {
        $this->stack = new SplStack();
    }

    public function pushSection(int $sectionId, int $level): void
    {
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
            return null;
        }
        
        return $this->stack->top()['id'];
    }

    public function getParentSectionId(int $level): ?int
    {
        // Ищем в стеке ближайшего родителя (уровень меньше текущего)
        // Для этого копируем стек, чтобы не портить оригинал
        // Но SplStack не клонируется легко, поэтому идем итератором
        
        foreach ($this->stack as $item) {
            if ($item['level'] < $level) {
                return $item['id'];
            }
        }
        
        return null;
    }
    
    public function reset(): void
    {
        $this->stack = new SplStack();
    }
}
