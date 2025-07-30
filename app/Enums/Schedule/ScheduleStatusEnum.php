<?php

namespace App\Enums\Schedule;

enum ScheduleStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Черновик',
            self::ACTIVE => 'Активный',
            self::PAUSED => 'Приостановлен',
            self::COMPLETED => 'Завершен',
            self::CANCELLED => 'Отменен',
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match($this) {
            self::DRAFT => in_array($status, [self::ACTIVE, self::CANCELLED]),
            self::ACTIVE => in_array($status, [self::PAUSED, self::COMPLETED, self::CANCELLED]),
            self::PAUSED => in_array($status, [self::ACTIVE, self::CANCELLED]),
            self::COMPLETED => false, // Завершенный график нельзя изменить
            self::CANCELLED => false, // Отмененный график нельзя изменить
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public static function activeStatuses(): array
    {
        return [self::DRAFT->value, self::ACTIVE->value, self::PAUSED->value];
    }

    public static function finalStatuses(): array
    {
        return [self::COMPLETED->value, self::CANCELLED->value];
    }
} 