<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Enums;

enum KnowledgeSurface: string
{
    case LK = 'lk';
    case ADMIN = 'admin';
    case MOBILE = 'mobile';
    case SUPERADMIN = 'superadmin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $surface): string => $surface->value,
            self::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $surface): array => [
                $surface->value => trans_message('knowledge_hub.surfaces.'.$surface->value),
            ])
            ->all();
    }
}
