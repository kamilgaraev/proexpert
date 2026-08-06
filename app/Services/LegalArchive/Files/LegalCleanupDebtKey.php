<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

final class LegalCleanupDebtKey
{
    public static function for(int $organizationId, string $storagePath): string
    {
        $canonical = $organizationId.':'.strlen($storagePath).':'.$storagePath;

        return hash('sha256', $canonical);
    }
}
