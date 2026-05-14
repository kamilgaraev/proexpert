<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Enums;

enum QualityDefectStatusEnum: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case ASSIGNED = 'assigned';
    case IN_PROGRESS = 'in_progress';
    case READY_FOR_REVIEW = 'ready_for_review';
    case RESOLVED = 'resolved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return trans_message("quality_control.statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::OPEN => 'amber',
            self::ASSIGNED => 'blue',
            self::IN_PROGRESS => 'indigo',
            self::READY_FOR_REVIEW => 'purple',
            self::RESOLVED => 'green',
            self::REJECTED => 'red',
            self::CANCELLED => 'slate',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::OPEN,
            self::ASSIGNED,
            self::IN_PROGRESS,
            self::REJECTED,
        ], true);
    }
}
