<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum InvoiceType: string
{
    case ACT = 'act';
    case ADVANCE = 'advance';
    case PROGRESS = 'progress';
    case FINAL = 'final';
    case MATERIAL_PURCHASE = 'material_purchase';
    case SERVICE = 'service';
    case EQUIPMENT = 'equipment';
    case SALARY = 'salary';
    case OTHER = 'other';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::ACT => 'По акту выполненных работ',
            self::ADVANCE => 'Авансовый платёж',
            self::PROGRESS => 'Промежуточный платёж',
            self::FINAL => 'Финальный расчёт',
            self::MATERIAL_PURCHASE => 'Закупка материалов',
            self::SERVICE => 'Оплата услуг',
            self::EQUIPMENT => 'Оплата оборудования',
            self::SALARY => 'Заработная плата',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * Требуется ли привязка к документу
     */
    public function requiresDocument(): bool
    {
        return in_array($this, [self::ACT, self::MATERIAL_PURCHASE]);
    }
}

