<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSnapshotRef
{
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
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $kind) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) !== 1
            || trim($formulaVersion) === ''
            || ($staleAt !== null && $staleAt < $generatedAt)
            || ($classification === ReportSnapshotClassification::OFFICIAL && $seal === null)
            || ($classification === ReportSnapshotClassification::OPERATIONAL && $seal !== null)
            || ($seal !== null && $seal->sealedAt < $generatedAt)) {
            throw new InvalidArgumentException('snapshot_identity_invalid');
        }

        CanonicalJson::encode($watermarks);
    }
}
