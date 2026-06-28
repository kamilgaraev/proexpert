<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Enums;

enum KnowledgeAudience: string
{
    case ALL = 'all';
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case FOREMAN = 'foreman';
    case WORKER = 'worker';
    case CONTRACTOR = 'contractor';
    case ACCOUNTANT = 'accountant';
    case SYSTEM_ADMIN = 'system_admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $audience): string => $audience->value,
            self::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $audience): array => [
                $audience->value => trans_message('knowledge_hub.audiences.'.$audience->value),
            ])
            ->all();
    }
}
