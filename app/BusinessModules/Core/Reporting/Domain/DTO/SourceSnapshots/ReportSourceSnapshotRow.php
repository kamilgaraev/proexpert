<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotRow
{
    public function __construct(
        public string $snapshotId,
        public int $ordinal,
        public string $rowKey,
        public array $payload,
        public Sha256Hash $payloadHash,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $snapshotId) !== 1
            || $ordinal < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$/D', $rowKey) !== 1
            || ! hash_equals($payloadHash->value, hash('sha256', CanonicalJson::encode($payload)))) {
            throw new InvalidArgumentException('report_source_snapshot_row_invalid');
        }
    }
}
