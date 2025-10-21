<?php

namespace App\Enums;

enum ContractType: string
{
    case MAIN = 'main';
    case AMENDMENT = 'amendment';
    case SUBCONTRACT = 'subcontract';

    public function label(): string
    {
        return match ($this) {
            self::MAIN => 'Основной контракт',
            self::AMENDMENT => 'Дополнительное соглашение',
            self::SUBCONTRACT => 'Субподрядный контракт',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MAIN => 'Основной договор между заказчиком и подрядчиком',
            self::AMENDMENT => 'Изменение условий основного контракта (Д/С)',
            self::SUBCONTRACT => 'Договор между подрядчиком и субподрядчиком',
        };
    }

    public function affectsParentAmount(): bool
    {
        return match ($this) {
            self::AMENDMENT => true,
            self::MAIN, self::SUBCONTRACT => false,
        };
    }

    public function canHaveParent(): bool
    {
        return match ($this) {
            self::AMENDMENT, self::SUBCONTRACT => true,
            self::MAIN => false,
        };
    }

    public function canHaveChildren(): bool
    {
        return match ($this) {
            self::MAIN, self::SUBCONTRACT => true,
            self::AMENDMENT => false,
        };
    }

    public static function fromNumber(string $number): self
    {
        if (preg_match('/^Д\/С|^ДС|дополнительн|доп\.\s*согл/ui', $number)) {
            return self::AMENDMENT;
        }

        return self::MAIN;
    }
}

