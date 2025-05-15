<?php

namespace App\Enums\RateCoefficient;

use App\Traits\Enums\HasValues;

enum RateCoefficientAppliesToEnum: string
{
    use HasValues;

    case MATERIAL_NORMS = 'material_norms';     // К нормам расхода материалов
    case WORK_COSTS = 'work_costs';             // К стоимости работ
    case LABOR_HOURS = 'labor_hours';           // К трудозатратам (человеко-часы)
    case GENERAL = 'general';                   // Общий коэффициент, может применяться к разным аспектам
} 