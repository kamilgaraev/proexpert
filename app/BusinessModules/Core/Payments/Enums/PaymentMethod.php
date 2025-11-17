<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case CARD = 'card';
    case ONLINE = 'online';
    case OFFSET = 'offset'; // Взаимозачёт
    case OTHER = 'other';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::CASH => 'Наличные',
            self::BANK_TRANSFER => 'Банковский перевод',
            self::CARD => 'Банковская карта',
            self::ONLINE => 'Онлайн оплата',
            self::OFFSET => 'Взаимозачёт',
            self::OTHER => 'Другое',
        };
    }

    /**
     * Требуется ли подтверждающий документ
     */
    public function requiresProof(): bool
    {
        return in_array($this, [self::CASH, self::BANK_TRANSFER]);
    }

    /**
     * Мгновенная ли операция
     */
    public function isInstant(): bool
    {
        return in_array($this, [self::CASH, self::CARD, self::ONLINE, self::OFFSET]);
    }
}

