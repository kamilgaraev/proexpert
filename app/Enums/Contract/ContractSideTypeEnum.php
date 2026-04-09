<?php

declare(strict_types=1);

namespace App\Enums\Contract;

enum ContractSideTypeEnum: string
{
    case CUSTOMER_TO_GENERAL_CONTRACTOR = 'customer_to_general_contractor';
    case GENERAL_CONTRACTOR_TO_CONTRACTOR = 'general_contractor_to_contractor';
    case GENERAL_CONTRACTOR_TO_SUPPLIER = 'general_contractor_to_supplier';
    case CONTRACTOR_TO_SUBCONTRACTOR = 'contractor_to_subcontractor';
    case CONTRACTOR_TO_SUPPLIER = 'contractor_to_supplier';
    case SUBCONTRACTOR_TO_SUPPLIER = 'subcontractor_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_TO_GENERAL_CONTRACTOR => 'Заказчик -> Генподрядчик',
            self::GENERAL_CONTRACTOR_TO_CONTRACTOR => 'Генподрядчик -> Подрядчик',
            self::GENERAL_CONTRACTOR_TO_SUPPLIER => 'Генподрядчик -> Поставщик',
            self::CONTRACTOR_TO_SUBCONTRACTOR => 'Подрядчик -> Субподрядчик',
            self::CONTRACTOR_TO_SUPPLIER => 'Подрядчик -> Поставщик',
            self::SUBCONTRACTOR_TO_SUPPLIER => 'Субподрядчик -> Поставщик',
        };
    }

    public function requiresProjectCustomer(): bool
    {
        return $this === self::CUSTOMER_TO_GENERAL_CONTRACTOR;
    }

    public function requiresContractor(): bool
    {
        return !$this->requiresSupplier();
    }

    public function requiresSupplier(): bool
    {
        return in_array($this, [
            self::GENERAL_CONTRACTOR_TO_SUPPLIER,
            self::CONTRACTOR_TO_SUPPLIER,
            self::SUBCONTRACTOR_TO_SUPPLIER,
        ], true);
    }

    public function allowsSelfExecution(): bool
    {
        return $this === self::GENERAL_CONTRACTOR_TO_CONTRACTOR;
    }
}
