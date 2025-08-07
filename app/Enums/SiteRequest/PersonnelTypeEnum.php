<?php

namespace App\Enums\SiteRequest;

use App\Traits\Enums\HasValues;

enum PersonnelTypeEnum: string
{
    use HasValues;

    case GENERAL_WORKER = 'general_worker';           // Разнорабочий
    case SKILLED_WORKER = 'skilled_worker';           // Квалифицированный рабочий
    case FOREMAN = 'foreman';                         // Прораб
    case ENGINEER = 'engineer';                       // Инженер
    case SPECIALIST = 'specialist';                   // Специалист
    case OPERATOR = 'operator';                       // Оператор техники
    case ELECTRICIAN = 'electrician';                 // Электрик
    case PLUMBER = 'plumber';                         // Сантехник
    case WELDER = 'welder';                          // Сварщик
    case CARPENTER = 'carpenter';                     // Плотник
    case MASON = 'mason';                            // Каменщик
    case PAINTER = 'painter';                        // Маляр
    case SECURITY = 'security';                      // Охрана
    case DRIVER = 'driver';                          // Водитель
    case OTHER = 'other';                            // Другое
}