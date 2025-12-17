<?php

namespace App\Enums\ConstructionJournal;

enum JournalStatusEnum: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Активный',
            self::ARCHIVED => 'Архивный',
            self::CLOSED => 'Закрытый',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::ACTIVE => 'success',
            self::ARCHIVED => 'warning',
            self::CLOSED => 'secondary',
        };
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

