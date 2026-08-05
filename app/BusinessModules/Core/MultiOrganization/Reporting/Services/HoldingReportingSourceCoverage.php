<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingReportingSourceCoverage
{
    public const CONTRACT_DIMENSIONS = 'contract_dimensions';

    public const ORGANIZATION_HIERARCHY = 'organization_hierarchy';

    public const ALLOCATION_DIMENSIONS = 'allocation_dimensions';

    public function assertCovers(string $sourceCode, DateTimeInterface $asOf): array
    {
        $coverage = DB::table('holding_reporting_context_coverage')
            ->where('source_code', $sourceCode)
            ->first(['coverage_started_at', 'evidence_hash']);
        if (! is_object($coverage)
            || preg_match('/^[a-f0-9]{64}$/D', (string) $coverage->evidence_hash) !== 1) {
            throw new InvalidArgumentException('holding_reporting_context_unavailable');
        }

        $startedAt = CarbonImmutable::parse((string) $coverage->coverage_started_at);
        $cutoff = CarbonImmutable::instance($asOf);
        if ($cutoff->lt($startedAt)) {
            throw new InvalidArgumentException('holding_reporting_context_historical_gap');
        }

        return [
            'coverage_started_at' => $startedAt->toISOString(),
            'evidence_hash' => (string) $coverage->evidence_hash,
        ];
    }
}
