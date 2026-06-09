<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Enums;

enum KnowledgeArticleKind: string
{
    case ARTICLE = 'article';
    case GUIDE = 'guide';
    case BEST_PRACTICE = 'best_practice';
    case TIP = 'tip';
    case CHANGELOG = 'changelog';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [
                $kind->value => trans_message('knowledge_hub.kinds.'.$kind->value),
            ])
            ->all();
    }
}
