<?php

namespace App\Enums;

enum ModuleDevelopmentStatus: string 
{
    case STABLE = 'stable';              // Стабильный релиз (по умолчанию)
    case BETA = 'beta';                  // Бета-тестирование
    case ALPHA = 'alpha';                // Альфа-версия
    case DEVELOPMENT = 'development';    // В активной разработке
    case COMING_SOON = 'coming_soon';    // Скоро появится
    case DEPRECATED = 'deprecated';      // Устаревший, не рекомендуется к использованию

    /**
     * Получить человекочитаемое название статуса
     */
    public function getLabel(): string
    {
        return match($this) {
            self::STABLE => 'Стабильный',
            self::BETA => 'Бета-тестирование',
            self::ALPHA => 'Альфа-версия',
            self::DEVELOPMENT => 'В разработке',
            self::COMING_SOON => 'Скоро',
            self::DEPRECATED => 'Устаревший',
        };
    }

    /**
     * Получить описание статуса
     */
    public function getDescription(): string
    {
        return match($this) {
            self::STABLE => 'Модуль полностью готов к использованию',
            self::BETA => 'Модуль в стадии бета-тестирования, возможны небольшие изменения',
            self::ALPHA => 'Модуль в стадии альфа-тестирования, возможны значительные изменения',
            self::DEVELOPMENT => 'Модуль находится в активной разработке',
            self::COMING_SOON => 'Модуль скоро будет доступен',
            self::DEPRECATED => 'Модуль устарел и будет удален в будущих версиях',
        };
    }

    /**
     * Получить цвет для отображения на фронтенде
     */
    public function getColor(): string
    {
        return match($this) {
            self::STABLE => 'green',
            self::BETA => 'blue',
            self::ALPHA => 'orange',
            self::DEVELOPMENT => 'yellow',
            self::COMING_SOON => 'purple',
            self::DEPRECATED => 'red',
        };
    }

    /**
     * Получить иконку для отображения на фронтенде
     */
    public function getIcon(): string
    {
        return match($this) {
            self::STABLE => 'check-circle',
            self::BETA => 'flask',
            self::ALPHA => 'beaker',
            self::DEVELOPMENT => 'code',
            self::COMING_SOON => 'clock',
            self::DEPRECATED => 'exclamation-triangle',
        };
    }

    /**
     * Можно ли активировать модуль в этом статусе
     */
    public function canBeActivated(): bool
    {
        return match($this) {
            self::STABLE, self::BETA, self::ALPHA => true,
            self::DEVELOPMENT, self::COMING_SOON, self::DEPRECATED => false,
        };
    }

    /**
     * Показывать ли предупреждение при активации
     */
    public function shouldShowWarning(): bool
    {
        return match($this) {
            self::STABLE => false,
            self::BETA, self::ALPHA, self::DEVELOPMENT, self::DEPRECATED => true,
            self::COMING_SOON => false,
        };
    }

    /**
     * Получить текст предупреждения
     */
    public function getWarningMessage(): ?string
    {
        return match($this) {
            self::STABLE, self::COMING_SOON => null,
            self::BETA => 'Этот модуль находится в стадии бета-тестирования. Возможны небольшие изменения в функциональности.',
            self::ALPHA => 'Этот модуль находится в стадии альфа-тестирования. Функциональность может значительно измениться.',
            self::DEVELOPMENT => 'Этот модуль находится в активной разработке и не готов к использованию.',
            self::DEPRECATED => 'Этот модуль устарел и будет удален в будущих версиях. Рекомендуется использовать альтернативные решения.',
        };
    }
}

