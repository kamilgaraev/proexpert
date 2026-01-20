<?php

namespace App\BusinessModules\Addons\AIEstimates\Enums;

enum FeedbackType: string
{
    case ACCEPTED = 'accepted';
    case EDITED = 'edited';
    case REJECTED = 'rejected';
    case PARTIALLY_ACCEPTED = 'partially_accepted';

    public function label(): string
    {
        return match($this) {
            self::ACCEPTED => 'Принято полностью',
            self::EDITED => 'Отредактировано',
            self::REJECTED => 'Отклонено',
            self::PARTIALLY_ACCEPTED => 'Принято частично',
        };
    }

    public function isPositive(): bool
    {
        return in_array($this, [self::ACCEPTED, self::PARTIALLY_ACCEPTED]);
    }
}
