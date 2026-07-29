<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ContractorScorecardSourceTuple
{
    private const EXPECTED_KINDS = [
        'baseline_schedule_variance',
        'supply_reliability',
        'quality_defect_flow',
        'safety_incident_actions',
        'marketplace_reviews',
    ];

    public function __construct(
        public ReportSnapshotRef $baselineScheduleVariance,
        public ReportSnapshotRef $supplyReliability,
        public ReportSnapshotRef $qualityDefectFlow,
        public ReportSnapshotRef $safetyIncidentActions,
        public ReportSnapshotRef $marketplaceReviews,
    ) {}

    public function assertCompatible(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): void {
        $refs = $this->refs();
        $kinds = array_map(static fn (ReportSnapshotRef $ref): string => $ref->kind, $refs);
        $scopeIdentity = $query->scope->canonicalIdentity();
        $cohortKey = $query->filters->values['cohort'] ?? null;
        $asOf = $query->asOf->format(DATE_ATOM);
        $now = new \DateTimeImmutable('now');

        if (
            $kinds !== self::EXPECTED_KINDS
            || count(array_unique($kinds)) !== count(self::EXPECTED_KINDS)
            || $context->scope->canonicalIdentity() !== $scopeIdentity
            || ($cohortKey !== null && ! is_string($cohortKey))
        ) {
            throw new InvalidArgumentException('contractor_scorecard_source_tuple_incompatible');
        }

        foreach ($refs as $ref) {
            if (
                $ref->scope->canonicalIdentity() !== $scopeIdentity
                || $ref->generatedAt > $now
                || ($ref->staleAt !== null && $ref->staleAt <= $now)
                || ! isset($ref->watermarks['source_schema_version'])
                || ! is_string($ref->watermarks['source_schema_version'])
                || trim($ref->watermarks['source_schema_version']) === ''
                || ($ref->watermarks['as_of'] ?? null) !== $asOf
                || ($ref->watermarks['cohort_key'] ?? null) !== $cohortKey
                || ($ref->watermarks['project_ids'] ?? null) !== $query->scope->projectIds
            ) {
                throw new InvalidArgumentException('contractor_scorecard_source_tuple_incompatible');
            }
        }
    }

    public function hash(): string
    {
        return hash('sha256', CanonicalJson::encode(array_map(
            static fn (ReportSnapshotRef $ref): array => [
                'kind' => $ref->kind,
                'snapshot_id' => $ref->id,
                'scope' => $ref->scope->canonicalIdentity(),
                'source_hash' => $ref->sourceHash->value,
                'formula_version' => $ref->formulaVersion,
                'source_schema_version' => $ref->watermarks['source_schema_version'] ?? null,
                'as_of' => $ref->watermarks['as_of'] ?? null,
                'cohort_key' => $ref->watermarks['cohort_key'] ?? null,
                'project_ids' => $ref->watermarks['project_ids'] ?? null,
                'generated_at' => $ref->generatedAt->format(DATE_ATOM),
                'stale_at' => $ref->staleAt?->format(DATE_ATOM),
            ],
            $this->refs(),
        )));
    }

    public function refs(): array
    {
        return [
            $this->baselineScheduleVariance,
            $this->supplyReliability,
            $this->qualityDefectFlow,
            $this->safetyIncidentActions,
            $this->marketplaceReviews,
        ];
    }
}
