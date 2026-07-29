<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

interface ReportArtifactVersionInventory
{
    /**
     * @return iterable<array{
     *     path: string,
     *     version_id: string,
     *     etag: string,
     *     size: int,
     *     sha256: string,
     *     mime: string,
     *     metadata: array<string, string>,
     *     created_at: \DateTimeImmutable
     * }>
     */
    public function forExport(int $organizationId, string $exportId): iterable;
}
