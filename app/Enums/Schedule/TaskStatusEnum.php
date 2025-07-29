<?php

namespace App\Enums\Schedule;

enum TaskStatusEnum: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case ON_HOLD = 'on_hold';
    case WAITING = 'waiting';

    public function label(): string
    {
        return match($this) {
            self::NOT_STARTED => 'Не начата',
            self::IN_PROGRESS => 'В работе',
            self::COMPLETED => 'Завершена',
            self::CANCELLED => 'Отменена',
            self::ON_HOLD => 'Приостановлена',
            self::WAITING => 'Ожидание',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::NOT_STARTED => '#6B7280', // серый
            self::IN_PROGRESS => '#3B82F6', // синий
            self::COMPLETED => '#10B981',   // зеленый
            self::CANCELLED => '#EF4444',   // красный
            self::ON_HOLD => '#F59E0B',     // оранжевый
            self::WAITING => '#8B5CF6',     // фиолетовый
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match($this) {
            self::NOT_STARTED => in_array($status, [self::IN_PROGRESS, self::ON_HOLD, self::CANCELLED]),
            self::IN_PROGRESS => in_array($status, [self::COMPLETED, self::ON_HOLD, self::WAITING, self::CANCELLED]),
            self::COMPLETED => false, // Завершенную задачу нельзя изменить
            self::CANCELLED => false, // Отмененную задачу нельзя изменить
            self::ON_HOLD => in_array($status, [self::IN_PROGRESS, self::CANCELLED]),
            self::WAITING => in_array($status, [self::IN_PROGRESS, self::ON_HOLD, self::CANCELLED]),
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::NOT_STARTED, self::IN_PROGRESS, self::WAITING]);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED]);
    }

    public function requiresProgress(): bool
    {
        return $this === self::IN_PROGRESS;
    }

    public static function activeStatuses(): array
    {
        return [self::NOT_STARTED, self::IN_PROGRESS, self::WAITING, self::ON_HOLD];
    }

    public static function workingStatuses(): array
    {
        return [self::IN_PROGRESS, self::WAITING];
    }
} 