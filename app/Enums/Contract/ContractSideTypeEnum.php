<?php

declare(strict_types=1);

namespace App\Enums\Contract;

enum ContractSideTypeEnum: string
{
    case GENERAL_CONTRACT = 'general_contract';
    case CONTRACT = 'contract';
    case GENERAL_CONTRACTOR_SUPPLY = 'general_contractor_supply';
    case SUBCONTRACT = 'subcontract';
    case CONTRACTOR_SUPPLY = 'contractor_supply';
    case SUBCONTRACTOR_SUPPLY = 'subcontractor_supply';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL_CONTRACT => 'Генеральный подряд',
            self::CONTRACT => 'Подряд',
            self::GENERAL_CONTRACTOR_SUPPLY => 'Поставка (генподрядчик)',
            self::SUBCONTRACT => 'Субподряд',
            self::CONTRACTOR_SUPPLY => 'Поставка (подрядчик)',
            self::SUBCONTRACTOR_SUPPLY => 'Поставка (субподрядчик)',
        };
    }

    public function requiresProjectCustomer(): bool
    {
        return $this === self::GENERAL_CONTRACT;
    }

    public function requiresContractor(): bool
    {
        return !$this->requiresSupplier();
    }

    public function requiresSupplier(): bool
    {
        return in_array($this, [
            self::GENERAL_CONTRACTOR_SUPPLY,
            self::CONTRACTOR_SUPPLY,
            self::SUBCONTRACTOR_SUPPLY,
        ], true);
    }

    public function allowsSelfExecution(): bool
    {
        return $this === self::CONTRACT;
    }

    public static function tryFromLegacy(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'customer_to_general_contractor', 'general_contract' => self::GENERAL_CONTRACT,
            'general_contractor_to_contractor', 'contract' => self::CONTRACT,
            'general_contractor_to_supplier', 'general_contractor_supply' => self::GENERAL_CONTRACTOR_SUPPLY,
            'contractor_to_subcontractor', 'subcontract' => self::SUBCONTRACT,
            'contractor_to_supplier', 'contractor_supply' => self::CONTRACTOR_SUPPLY,
            'subcontractor_to_supplier', 'subcontractor_supply' => self::SUBCONTRACTOR_SUPPLY,
            default => self::tryFrom($value),
        };
    }
}
