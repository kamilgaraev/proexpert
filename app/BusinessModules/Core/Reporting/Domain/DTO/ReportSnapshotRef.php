<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotIdentityViolationReason;
use App\BusinessModules\Core\Reporting\Domain\Exceptions\ReportSnapshotIdentityViolation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSnapshotRef
{
    public Sha256Hash $canonicalReportHash;

    public Sha256Hash $materializedSourceHash;

    public function __construct(
        public string $kind,
        public string $id,
        public ReportScope $scope,
        public Sha256Hash $definitionHash,
        public string $formulaVersion,
        public Sha256Hash $sourceHash,
        public DateTimeImmutable $generatedAt,
        public ?DateTimeImmutable $staleAt,
        public array $watermarks,
        public ReportSnapshotClassification $classification,
        public ?ReportSnapshotSeal $seal,
        ?Sha256Hash $materializedSourceHash = null,
    ) {
        $this->canonicalReportHash = $sourceHash;
        $this->materializedSourceHash = $materializedSourceHash ?? $sourceHash;
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $kind) !== 1) {
            throw new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::INVALID_KIND);
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) !== 1) {
            throw new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::INVALID_ID);
        }

        if (trim($formulaVersion) === ''
            || ($staleAt !== null && $staleAt < $generatedAt)) {
            throw new InvalidArgumentException('snapshot_identity_invalid');
        }

        if ($classification === ReportSnapshotClassification::OFFICIAL && $seal === null) {
            throw new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::OFFICIAL_SEAL_REQUIRED);
        }

        if ($classification === ReportSnapshotClassification::OPERATIONAL && $seal !== null) {
            throw new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::OPERATIONAL_SEAL_FORBIDDEN);
        }

        if ($seal !== null && $seal->sealedAt < $generatedAt) {
            throw new ReportSnapshotIdentityViolation(ReportSnapshotIdentityViolationReason::SEAL_TIME_INVALID);
        }

        CanonicalJson::encode($watermarks);
    }
}
