<?php

namespace App\Enums;

enum EstimatePositionItemType: string
{
    case WORK = 'work';
    case MATERIAL = 'material';
    case EQUIPMENT = 'equipment';
    case LABOR = 'labor';

    /**
     * Получить все значения
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Получить метки
     */
    public function label(): string
    {
        return match($this) {
            self::WORK => 'Работа',
            self::MATERIAL => 'Материал',
            self::EQUIPMENT => 'Оборудование',
            self::LABOR => 'Труд',
        };
    }

    /**
     * Проверка, является ли работой
     */
    public function isWork(): bool
    {
        return $this === self::WORK;
    }

    /**
     * Проверка, является ли материалом
     */
    public function isMaterial(): bool
    {
        return $this === self::MATERIAL;
    }

    /**
     * Проверка, является ли оборудованием
     */
    public function isEquipment(): bool
    {
        return $this === self::EQUIPMENT;
    }

    /**
     * Проверка, является ли трудом
     */
    public function isLabor(): bool
    {
        return $this === self::LABOR;
    }
}

