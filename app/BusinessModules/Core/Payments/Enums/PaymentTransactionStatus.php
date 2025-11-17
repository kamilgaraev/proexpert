<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum PaymentTransactionStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Ожидает обработки',
            self::PROCESSING => 'В обработке',
            self::COMPLETED => 'Завершён',
            self::FAILED => 'Ошибка',
            self::CANCELLED => 'Отменён',
            self::REFUNDED => 'Возвращён',
        };
    }

    /**
     * Является ли финальным статусом
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED, self::REFUNDED]);
    }

    /**
     * Успешная ли транзакция
     */
    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }
}

