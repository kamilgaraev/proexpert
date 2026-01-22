<?php

namespace App\BusinessModules\Features\BudgetEstimates\Enums;

class PriceStrategyEnum
{
    public const TOP = 'top';       // Берем верхнее значение
    public const BOTTOM = 'bottom'; // Берем нижнее значение
    public const MAX = 'max';       // Берем максимальное значение
    public const DEFAULT = 'default'; // Берем как есть (или первое)
}
