<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Приоритеты заявок с объекта
 */
enum SiteRequestPriorityEnum: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case URGENT = 'urgent';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::LOW => 'Низкий',
            self::MEDIUM => 'Средний',
            self::HIGH => 'Высокий',
            self::URGENT => 'Срочно',
        };
    }

    /**
     * Получить цвет приоритета
     */
    public function color(): string
    {
        return match($this) {
            self::LOW => '#4CAF50',
            self::MEDIUM => '#FF9800',
            self::HIGH => '#FF5722',
            self::URGENT => '#F44336',
        };
    }

    /**
     * Получить иконку приоритета
     */
    public function icon(): string
    {
        return match($this) {
            self::LOW => 'arrow-down',
            self::MEDIUM => 'minus',
            self::HIGH => 'arrow-up',
            self::URGENT => 'exclamation',
        };
    }

    /**
     * Получить числовой вес приоритета (для сортировки)
     */
    public function weight(): int
    {
        return match($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::URGENT => 4,
        };
    }

    /**
     * Получить все приоритеты как массив для валидации
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Получить все приоритеты с метками для выбора
     */
    public static function options(): array
    {
        return array_map(
            fn(self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'icon' => $case->icon(),
                'weight' => $case->weight(),
            ],
            self::cases()
        );
    }
}

