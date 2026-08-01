<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotDrillRow
{
    public function __construct(
        public string $snapshotId,
        public string $rowKey,
        public string $columnId,
        public int $ordinal,
        public array $payload,
        public Sha256Hash $payloadHash,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$/D', $rowKey) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1
            || $ordinal < 1
            || ! hash_equals($payloadHash->value, hash('sha256', CanonicalJson::encode($payload)))) {
            throw new InvalidArgumentException('report_source_snapshot_drill_row_invalid');
        }
    }
}
