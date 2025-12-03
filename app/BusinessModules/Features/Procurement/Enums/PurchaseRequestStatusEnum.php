<?php

namespace App\BusinessModules\Features\Procurement\Enums;

enum PurchaseRequestStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PENDING => 'На рассмотрении',
            self::APPROVED => 'Одобрена',
            self::REJECTED => 'Отклонена',
            self::CANCELLED => 'Отменена',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'yellow',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::CANCELLED => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::CANCELLED]);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::PENDING]);
    }
}

