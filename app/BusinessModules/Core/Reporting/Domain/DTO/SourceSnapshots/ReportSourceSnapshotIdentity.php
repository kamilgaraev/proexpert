<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSourceSnapshotIdentity
{
    public function __construct(
        public string $sourceKind,
        public string $reportCode,
        public string $schemaVersion,
        public ReportScope $scope,
        public Sha256Hash $queryHash,
        public string $sourceVersion,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $sourceKind) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($schemaVersion) === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $sourceVersion) !== 1) {
            throw new InvalidArgumentException('report_source_snapshot_identity_invalid');
        }
    }

    public function scopeIdentity(): array
    {
        return $this->scope->canonicalIdentity();
    }

    public function scopeIdentityHash(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($this->scopeIdentity())));
    }

    public function matches(ReportSourceSnapshotHeader $header): bool
    {
        return $this->sourceKind === $header->sourceKind
            && $this->reportCode === $header->reportCode
            && $this->schemaVersion === $header->schemaVersion
            && $this->scopeIdentity() === $header->scopeIdentity()
            && hash_equals($this->queryHash->value, $header->queryHash->value);
    }
}
