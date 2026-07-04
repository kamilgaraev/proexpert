<?php

declare(strict_types=1);

namespace App\Enums;

enum CounterpartyRoleEnum: string
{
    case CUSTOMER = 'customer';
    case GENERAL_CONTRACTOR = 'general_contractor';
    case CONTRACTOR = 'contractor';
    case SUBCONTRACTOR = 'subcontractor';
    case SUPPLIER = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Заказчик',
            self::GENERAL_CONTRACTOR => 'Генподрядчик',
            self::CONTRACTOR => 'Подрядчик',
            self::SUBCONTRACTOR => 'Субподрядчик',
            self::SUPPLIER => 'Поставщик',
        };
    }
}
