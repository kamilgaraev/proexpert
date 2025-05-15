<?php

namespace App\Enums\RateCoefficient;

use App\Traits\Enums\HasValues;

enum RateCoefficientScopeEnum: string
{
    use HasValues;

    case GLOBAL_ORG = 'global_org';             // Действует на всю организацию
    case PROJECT = 'project';                   // Действует на конкретный проект (или список проектов)
    case WORK_TYPE_CATEGORY = 'work_type_category'; // Действует на категорию видов работ
    case WORK_TYPE = 'work_type';               // Действует на конкретный вид работ (или список)
    case MATERIAL_CATEGORY = 'material_category'; // Действует на категорию материалов
    case MATERIAL = 'material';                 // Действует на конкретный материал (или список)
} 