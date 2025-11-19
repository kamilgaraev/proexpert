<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum PaymentDocumentType: string
{
    case PAYMENT_REQUEST = 'payment_request';      // Платежное требование (от подрядчика)
    case INVOICE = 'invoice';                      // Счет на оплату (исходящий)
    case PAYMENT_ORDER = 'payment_order';          // Платежное поручение (на оплату)
    case INCOMING_PAYMENT = 'incoming_payment';    // Входящий платеж
    case EXPENSE = 'expense';                      // Расходный ордер
    case OFFSET_ACT = 'offset_act';               // Акт взаимозачета

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::PAYMENT_REQUEST => 'Платежное требование',
            self::INVOICE => 'Счет на оплату',
            self::PAYMENT_ORDER => 'Платежное поручение',
            self::INCOMING_PAYMENT => 'Входящий платеж',
            self::EXPENSE => 'Расходный ордер',
            self::OFFSET_ACT => 'Акт взаимозачета',
        };
    }

    /**
     * Требует ли документ утверждения
     */
    public function requiresApproval(): bool
    {
        return in_array($this, [
            self::PAYMENT_REQUEST,
            self::PAYMENT_ORDER,
            self::EXPENSE,
        ]);
    }

    /**
     * Является ли исходящим платежом
     */
    public function isOutgoing(): bool
    {
        return in_array($this, [
            self::PAYMENT_ORDER,
            self::EXPENSE,
        ]);
    }

    /**
     * Является ли входящим платежом
     */
    public function isIncoming(): bool
    {
        return in_array($this, [
            self::PAYMENT_REQUEST,
            self::INCOMING_PAYMENT,
            self::INVOICE,
        ]);
    }
}

