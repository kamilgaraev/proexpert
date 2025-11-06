<?php

namespace App\BusinessModules\Features\ScheduleManagement\Enums;

enum EventTypeEnum: string
{
    case INSPECTION = 'inspection';
    case DELIVERY = 'delivery';
    case MEETING = 'meeting';
    case MAINTENANCE = 'maintenance';
    case WEATHER = 'weather';
    case OTHER = 'other';

    /**
     * Получить название типа события
     */
    public function label(): string
    {
        return match($this) {
            self::INSPECTION => 'Инспекция',
            self::DELIVERY => 'Поставка',
            self::MEETING => 'Совещание',
            self::MAINTENANCE => 'Обслуживание',
            self::WEATHER => 'Погодные условия',
            self::OTHER => 'Другое',
        };
    }

    /**
     * Получить цвет типа события
     */
    public function color(): string
    {
        return match($this) {
            self::INSPECTION => '#ef4444',
            self::DELIVERY => '#3b82f6',
            self::MEETING => '#8b5cf6',
            self::MAINTENANCE => '#f59e0b',
            self::WEATHER => '#06b6d4',
            self::OTHER => '#6b7280',
        };
    }

    /**
     * Получить иконку типа события
     */
    public function icon(): string
    {
        return match($this) {
            self::INSPECTION => 'clipboard-check',
            self::DELIVERY => 'truck',
            self::MEETING => 'users',
            self::MAINTENANCE => 'wrench',
            self::WEATHER => 'cloud',
            self::OTHER => 'calendar',
        };
    }

    /**
     * Получить описание типа события
     */
    public function description(): string
    {
        return match($this) {
            self::INSPECTION => 'Проверки контролирующих органов (Ростехнадзор, пожарные)',
            self::DELIVERY => 'Поставки материалов и оборудования',
            self::MEETING => 'Совещания на объекте',
            self::MAINTENANCE => 'Обслуживание техники и оборудования',
            self::WEATHER => 'Ограничения из-за погодных условий',
            self::OTHER => 'Прочие события',
        };
    }

    /**
     * Все типы событий
     */
    public static function all(): array
    {
        return [
            self::INSPECTION,
            self::DELIVERY,
            self::MEETING,
            self::MAINTENANCE,
            self::WEATHER,
            self::OTHER,
        ];
    }

    /**
     * Получить список для выбора
     */
    public static function options(): array
    {
        return array_map(
            fn($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'color' => $type->color(),
                'icon' => $type->icon(),
                'description' => $type->description(),
            ],
            self::all()
        );
    }
}

