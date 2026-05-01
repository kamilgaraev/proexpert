<?php

namespace App\BusinessModules\Features\Procurement\Enums;

enum PurchaseOrderStatusEnum: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case CONFIRMED = 'confirmed';
    case IN_DELIVERY = 'in_delivery';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::SENT => 'Отправлен поставщику',
            self::CONFIRMED => 'Подтвержден поставщиком',
            self::IN_DELIVERY => 'В доставке',
            self::PARTIALLY_DELIVERED => 'Частично доставлен',
            self::DELIVERED => 'Доставлен',
            self::CANCELLED => 'Отменен',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'blue',
            self::CONFIRMED => 'green',
            self::IN_DELIVERY => 'yellow',
            self::PARTIALLY_DELIVERED => 'yellow',
            self::DELIVERED => 'green',
            self::CANCELLED => 'red',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::CANCELLED]);
    }

    public function canBeSent(): bool
    {
        return $this === self::DRAFT;
    }

    public function canBeConfirmed(): bool
    {
        return $this === self::SENT;
    }

    public function canReceiveMaterials(): bool
    {
        return in_array($this, [self::CONFIRMED, self::IN_DELIVERY, self::PARTIALLY_DELIVERED], true);
    }
}

