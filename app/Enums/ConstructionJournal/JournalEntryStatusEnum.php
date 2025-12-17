<?php

namespace App\Enums\ConstructionJournal;

enum JournalEntryStatusEnum: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Черновик',
            self::SUBMITTED => 'На утверждении',
            self::APPROVED => 'Утверждено',
            self::REJECTED => 'Отклонено',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'secondary',
            self::SUBMITTED => 'info',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
        };
    }

    public function canEdit(): bool
    {
        return in_array($this, [self::DRAFT, self::REJECTED]);
    }

    public function canSubmit(): bool
    {
        return $this === self::DRAFT;
    }

    public function canApprove(): bool
    {
        return $this === self::SUBMITTED;
    }

    public function canReject(): bool
    {
        return $this === self::SUBMITTED;
    }

    public static function getOptions(): array
    {
        return array_map(
            fn($case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases()
        );
    }
}

