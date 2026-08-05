<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingContractDimensionSnapshot;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingContractDimensionResolver
{
    public function __construct(
        private HoldingReportingSourceCoverage $coverage = new HoldingReportingSourceCoverage,
    ) {}

    public function resolve(
        int $organizationId,
        int $contractId,
        DateTimeInterface $asOf,
    ): HoldingContractDimensionSnapshot {
        if (min($organizationId, $contractId) < 1) {
            throw new InvalidArgumentException('holding_contract_dimension_identity_invalid');
        }

        $snapshot = $this->resolveByContractId($contractId, $asOf);
        if ($snapshot->organizationId !== $organizationId) {
            throw new InvalidArgumentException('holding_contract_dimension_unavailable');
        }

        return $snapshot;
    }

    private function resolveByContractId(
        int $contractId,
        DateTimeInterface $asOf,
    ): HoldingContractDimensionSnapshot {
        if ($contractId < 1) {
            throw new InvalidArgumentException('holding_contract_dimension_identity_invalid');
        }

        $coverage = $this->coverage->assertCovers(
            HoldingReportingSourceCoverage::CONTRACT_DIMENSIONS,
            $asOf,
        );
        $event = DB::table('holding_contract_dimension_events')
            ->where('contract_id', $contractId)
            ->where('observed_at', '<=', $asOf)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
        if (! is_object($event) || (bool) $event->is_deleted) {
            throw new InvalidArgumentException('holding_contract_dimension_unavailable');
        }

        return new HoldingContractDimensionSnapshot(
            (int) $event->id,
            (int) $event->contract_id,
            (int) $event->organization_id,
            $event->contractor_id === null ? null : (int) $event->contractor_id,
            $event->counterparty_organization_id === null
                ? null
                : (int) $event->counterparty_organization_id,
            (string) $event->contract_status,
            $event->work_type_category === null ? null : (string) $event->work_type_category,
            $event->total_amount === null ? null : (string) $event->total_amount,
            (string) $event->currency,
            (string) $event->evidence_hash,
            (string) $coverage['coverage_started_at'],
        );
    }
}
