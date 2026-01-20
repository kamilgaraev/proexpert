<?php

namespace App\BusinessModules\Addons\AIEstimates\Enums;

enum GenerationStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Ожидает обработки',
            self::PROCESSING => 'Обрабатывается',
            self::COMPLETED => 'Завершено',
            self::FAILED => 'Ошибка',
            self::CANCELLED => 'Отменено',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED]);
    }

    public function canTransitionTo(self $status): bool
    {
        return match($this) {
            self::PENDING => in_array($status, [self::PROCESSING, self::CANCELLED]),
            self::PROCESSING => in_array($status, [self::COMPLETED, self::FAILED, self::CANCELLED]),
            self::COMPLETED, self::FAILED, self::CANCELLED => false,
        };
    }
}
