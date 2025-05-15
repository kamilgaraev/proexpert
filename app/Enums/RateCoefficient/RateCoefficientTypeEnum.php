<?php

namespace App\Enums\RateCoefficient;

use App\Traits\Enums\HasValues;

enum RateCoefficientTypeEnum: string
{
    use HasValues;

    case PERCENTAGE = 'percentage'; // Значение является процентом
    case FIXED_ADDITION = 'fixed_addition'; // Значение является фиксированной суммой для добавления
} 