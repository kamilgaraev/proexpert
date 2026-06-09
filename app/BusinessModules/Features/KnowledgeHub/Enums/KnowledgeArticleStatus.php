<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Enums;

enum KnowledgeArticleStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [
                $status->value => trans_message('knowledge_hub.statuses.'.$status->value),
            ])
            ->all();
    }
}
