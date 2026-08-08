<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSnapshotSealVerificationInput
{
    public function __construct(
        public ReportSnapshotSeal $seal,
        public string $snapshotId,
        public string $snapshotKind,
        public ReportSnapshotClassification $snapshotClassification,
        public DateTimeImmutable $generatedAt,
        public Sha256Hash $calculatedSourceHash,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $snapshotId) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $snapshotKind) !== 1
            || $snapshotClassification !== ReportSnapshotClassification::OFFICIAL
            || $seal->sealedAt < $generatedAt
            || !hash_equals($seal->sealedPayloadHash->value, $calculatedSourceHash->value)) {
            throw new InvalidArgumentException('report_snapshot_seal_verification_input_invalid');
        }
    }

}
