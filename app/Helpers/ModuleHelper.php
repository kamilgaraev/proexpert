<?php

namespace App\Helpers;

class ModuleHelper
{
    private static array $moduleMap = [
        'project-management' => 'Управление проектами',
        'contract-management' => 'Управление контрактами',
        'basic-warehouse' => 'Базовое управление складом',
        'schedule-management' => 'Управление графиком',
        'advanced-dashboard' => 'Расширенная панель',
        'time-tracking' => 'Учет рабочего времени',
        'workflow-management' => 'Управление процессами',
        'catalog-management' => 'Управление каталогом',
    ];

    public static function formatModules(array $moduleSlugs): array
    {
        return array_map(function ($slug) {
            return [
                'value' => $slug,
                'label' => self::$moduleMap[$slug] ?? ucfirst(str_replace('-', ' ', $slug)),
            ];
        }, array_unique($moduleSlugs));
    }

    public static function getModuleName(string $slug): string
    {
        return self::$moduleMap[$slug] ?? ucfirst(str_replace('-', ' ', $slug));
    }
}
