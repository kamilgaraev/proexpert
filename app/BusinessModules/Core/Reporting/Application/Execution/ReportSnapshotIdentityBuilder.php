<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportSnapshotIdentityBuilder
{
    public function __construct(private CanonicalReportSourceHashBuilder $canonical) {}

    public function build(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
    ): Sha256Hash {
        if (! hash_equals($snapshot->sourceHash->value, $result->provenance->sourceHash->value)
            || ! hash_equals($snapshot->sourceHash->value, $result->metadata->snapshot->sourceHash->value)) {
            throw new InvalidArgumentException('report_source_hash_identity_mismatch');
        }

        return $this->canonical->build($query, $snapshot, $result);
    }
}
