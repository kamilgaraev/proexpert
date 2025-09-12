<?php

namespace App\Enums;

enum BillingModel: string 
{
    case FREE = 'free';              // Бесплатные
    case SUBSCRIPTION = 'subscription'; // Подписочные
    case ONE_TIME = 'one_time';      // Одноразовые  
    case USAGE_BASED = 'usage_based'; // По использованию
    case FREEMIUM = 'freemium';      // Базовый бесплатно + премиум
}
