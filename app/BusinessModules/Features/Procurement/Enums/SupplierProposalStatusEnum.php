<?php

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierProposalStatusEnum: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::SUBMITTED => 'Отправлено',
            self::ACCEPTED => 'Принято',
            self::REJECTED => 'Отклонено',
            self::EXPIRED => 'Истек срок действия',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SUBMITTED => 'blue',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
            self::EXPIRED => 'orange',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::EXPIRED]);
    }

    public function canBeAccepted(): bool
    {
        return $this === self::SUBMITTED;
    }
}

