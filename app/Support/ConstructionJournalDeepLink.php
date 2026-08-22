<?php

declare(strict_types=1);

namespace App\Support;

final class ConstructionJournalDeepLink
{
    public static function entryPath(int $_journalId, int $entryId): string
    {
        return "/journal-entries/{$entryId}";
    }

    public static function entryUrl(int $journalId, int $entryId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .self::entryPath($journalId, $entryId);
    }
}
