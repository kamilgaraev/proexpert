<?php

namespace App\Enums;

enum EstimatePositionItemType: string
{
    case WORK = 'work';
    case MATERIAL = 'material';
    case EQUIPMENT = 'equipment';
    case MACHINERY = 'machinery';
    case LABOR = 'labor';
    case SUMMARY = 'summary';

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
            self::MACHINERY => 'Механизм',
            self::LABOR => 'Труд',
            self::SUMMARY => 'Итого',
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
     * Проверка, является ли механизмом
     */
    public function isMachinery(): bool
    {
        return $this === self::MACHINERY;
    }

    /**
     * Проверка, является ли трудом
     */
    public function isLabor(): bool
    {
        return $this === self::LABOR;
    }

    /**
     * Проверка, является ли итоговой строкой
     */
    public function isSummary(): bool
    {
        return $this === self::SUMMARY;
    }
}

