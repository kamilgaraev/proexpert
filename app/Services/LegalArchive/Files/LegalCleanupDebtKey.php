<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

final class LegalCleanupDebtKey
{
    public static function for(int $organizationId, string $storagePath, ?string $storageVersionId): string
    {
        $version = $storageVersionId ?? 'legacy';
        $canonical = $organizationId.':'.strlen($storagePath).':'.$storagePath.':'.strlen($version).':'.$version;

        return hash('sha256', $canonical);
    }
}
