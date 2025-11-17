<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum InvoiceStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';
    case WRITTEN_OFF = 'written_off';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Черновик',
            self::ISSUED => 'Выставлен',
            self::PARTIALLY_PAID => 'Частично оплачен',
            self::PAID => 'Оплачен',
            self::OVERDUE => 'Просрочен',
            self::CANCELLED => 'Отменён',
            self::WRITTEN_OFF => 'Списан',
        };
    }

    /**
     * Может ли счёт быть оплачен
     */
    public function canBePaid(): bool
    {
        return in_array($this, [self::ISSUED, self::PARTIALLY_PAID, self::OVERDUE]);
    }

    /**
     * Может ли счёт быть отменён
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::DRAFT, self::ISSUED]);
    }
}

