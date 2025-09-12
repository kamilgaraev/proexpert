<?php

namespace App\Domain\Authorization\Enums;

/**
 * Enum для типов условий ролей (ABAC)
 */
enum ConditionType: string
{
    case TIME = 'time';
    case LOCATION = 'location';
    case BUDGET = 'budget';
    case PROJECT_COUNT = 'project_count';
    case CUSTOM = 'custom';

    /**
     * Получить все значения
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Получить описание типа условия
     */
    public function getDescription(): string
    {
        return match($this) {
            self::TIME => 'Временные условия (рабочие часы, дни недели)',
            self::LOCATION => 'Географические условия (IP, регион, геолокация)',
            self::BUDGET => 'Бюджетные ограничения (лимиты операций)',
            self::PROJECT_COUNT => 'Ограничения по количеству проектов',
            self::CUSTOM => 'Кастомные условия',
        };
    }

    /**
     * Получить иконку для UI
     */
    public function getIcon(): string
    {
        return match($this) {
            self::TIME => 'clock',
            self::LOCATION => 'map-pin',
            self::BUDGET => 'dollar-sign',
            self::PROJECT_COUNT => 'hash',
            self::CUSTOM => 'settings',
        };
    }

    /**
     * Получить цвет для UI
     */
    public function getColor(): string
    {
        return match($this) {
            self::TIME => 'blue',
            self::LOCATION => 'green',
            self::BUDGET => 'yellow',
            self::PROJECT_COUNT => 'purple',
            self::CUSTOM => 'gray',
        };
    }

    /**
     * Проверить, требует ли тип контекст для оценки
     */
    public function requiresContext(): bool
    {
        return match($this) {
            self::TIME => false,
            self::LOCATION => true,
            self::BUDGET => true,
            self::PROJECT_COUNT => false,
            self::CUSTOM => true,
        };
    }
}
