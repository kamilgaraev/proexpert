<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierRequestStatusEnum: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case RESPONDED = 'responded';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::SENT => 'Отправлена',
            self::RESPONDED => 'Есть ответ',
            self::CANCELLED => 'Отменена',
            self::EXPIRED => 'Истек срок',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'blue',
            self::RESPONDED => 'green',
            self::CANCELLED => 'red',
            self::EXPIRED => 'orange',
        };
    }

    public function canBeSent(): bool
    {
        return $this === self::DRAFT;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::DRAFT, self::SENT], true);
    }
}
