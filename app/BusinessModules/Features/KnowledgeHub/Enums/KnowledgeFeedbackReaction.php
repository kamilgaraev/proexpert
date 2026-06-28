<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Enums;

enum KnowledgeFeedbackReaction: string
{
    case HELPFUL = 'helpful';
    case NOT_HELPFUL = 'not_helpful';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $reaction): string => $reaction->value,
            self::cases(),
        );
    }
}
