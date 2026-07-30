<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

interface ReportSnapshotSealStore
{
    public function create(
        string $snapshotKind,
        string $snapshotId,
        DateTimeImmutable $generatedAt,
        Sha256Hash $sourceHash,
        DateTimeImmutable $sealedAt,
    ): ReportSnapshotSeal;

    public function get(string $snapshotKind, string $snapshotId): ReportSnapshotSeal;
}
