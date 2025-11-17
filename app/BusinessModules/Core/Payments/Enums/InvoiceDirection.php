<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum InvoiceDirection: string
{
    case INCOMING = 'incoming'; // Нам должны (дебиторка)
    case OUTGOING = 'outgoing'; // Мы должны (кредиторка)

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::INCOMING => 'Входящий (дебиторка)',
            self::OUTGOING => 'Исходящий (кредиторка)',
        };
    }

    /**
     * Получить описание
     */
    public function description(): string
    {
        return match($this) {
            self::INCOMING => 'Нам должны оплатить',
            self::OUTGOING => 'Мы должны оплатить',
        };
    }
}

