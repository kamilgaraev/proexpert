<?php

namespace App\Enums;

enum ModuleType: string 
{
    case CORE = 'core';              // Базовая функциональность (организации, пользователи)
    case FEATURE = 'feature';        // Основные модули (проекты, материалы)
    case ADDON = 'addon';            // Дополнения (интеграции, BI)
    case SERVICE = 'service';        // Одноразовые услуги (экспорт, импорт)
    case EXTENSION = 'extension';    // Расширения функций
}
