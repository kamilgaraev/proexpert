<?php

namespace App\BusinessModules\Core\Payments\Enums;

enum PaymentDocumentStatus: string
{
    case DRAFT = 'draft';                          // Черновик
    case SUBMITTED = 'submitted';                  // Отправлен на рассмотрение
    case PENDING_APPROVAL = 'pending_approval';    // Ожидает утверждения
    case APPROVED = 'approved';                    // Утвержден
    case SCHEDULED = 'scheduled';                  // Запланирован к оплате
    case PAID = 'paid';                           // Оплачен
    case PARTIALLY_PAID = 'partially_paid';       // Частично оплачен
    case REJECTED = 'rejected';                    // Отклонен
    case CANCELLED = 'cancelled';                  // Отменен

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Черновик',
            self::SUBMITTED => 'Отправлен',
            self::PENDING_APPROVAL => 'На согласовании',
            self::APPROVED => 'Утвержден',
            self::SCHEDULED => 'Запланирован',
            self::PAID => 'Оплачен',
            self::PARTIALLY_PAID => 'Частично оплачен',
            self::REJECTED => 'Отклонен',
            self::CANCELLED => 'Отменен',
        };
    }

    /**
     * Может ли документ быть оплачен
     */
    public function canBePaid(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::SCHEDULED,
            self::PARTIALLY_PAID,
        ]);
    }

    /**
     * Может ли документ быть отменен
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::SUBMITTED,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::SCHEDULED,
        ]);
    }

    /**
     * Может ли документ быть редактирован
     */
    public function canBeEdited(): bool
    {
        return in_array($this, [
            self::DRAFT,
        ]);
    }

    /**
     * Финальный ли статус (нельзя изменить)
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::PAID,
            self::REJECTED,
            self::CANCELLED,
        ]);
    }

    /**
     * Активный ли статус (требует действий)
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::SUBMITTED,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::SCHEDULED,
            self::PARTIALLY_PAID,
        ]);
    }
}

