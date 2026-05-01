<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum PurchaseReceiptStatusEnum: string
{
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::POSTED => 'Проведена',
            self::CANCELLED => 'Отменена',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::POSTED => 'green',
            self::CANCELLED => 'red',
        };
    }
}
