<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

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
    ) {
        if (trim($kind) === '' || trim($id) === '' || trim($formulaVersion) === '' || ($staleAt !== null && $staleAt < $generatedAt)) {
            throw new InvalidArgumentException('snapshot_identity_invalid');
        }

        CanonicalJson::encode($watermarks);
    }
}
