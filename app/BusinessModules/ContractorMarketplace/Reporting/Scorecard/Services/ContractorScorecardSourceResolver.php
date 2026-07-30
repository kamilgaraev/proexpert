<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ContractorScorecardSourceResolver
{
    private const REPORT_CODES = [
        'baseline_schedule_variance',
        'supply_reliability',
        'quality_defect_flow',
        'safety_incident_actions',
    ];

    public function __construct(
        private ContractorReviewSnapshotResolver $reviews,
    ) {}

    public function resolve(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ContractorScorecardSourceTuple {
        try {
            $refs = [];
            foreach (self::REPORT_CODES as $code) {
                $refs[$code] = $this->reportSnapshot($context, $query, $code);
            }
            $tuple = new ContractorScorecardSourceTuple(
                $refs['baseline_schedule_variance'],
                $refs['supply_reliability'],
                $refs['quality_defect_flow'],
                $refs['safety_incident_actions'],
                $this->reviews->resolve($query),
            );
            $tuple->assertCompatible($context, $query);

            return $tuple;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                [],
                $exception,
            );
        }
    }

    private function reportSnapshot(
        ReportExecutionContext $context,
        ReportQuery $query,
        string $code,
    ): ReportSnapshotRef {
        $candidates = ReportRunRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('report_code', $code)
            ->where('status', 'ready')
            ->where('as_of', $query->asOf)
            ->whereRaw(
                'scope_project_ids = ?::jsonb',
                [CanonicalJson::encode($query->scope->projectIds)],
            )
            ->whereRaw(
                'scope_holding_organization_ids = ?::jsonb',
                [CanonicalJson::encode($query->scope->holdingOrganizationIds)],
            )
            ->whereRaw(
                'scope_resources = ?::jsonb',
                [CanonicalJson::encode(array_map(
                    static fn ($resource): array => $resource->canonicalIdentity(),
                    $query->scope->resources,
                ))],
            )
            ->where('scope_timezone', $query->scope->timezone->getName())
            ->whereRaw(
                'filters = ?::jsonb',
                [CanonicalJson::encode($query->filters->values)],
            )
            ->whereNotNull('snapshot_id')
            ->whereNotNull('source_hash')
            ->orderByDesc('as_of')
            ->orderByDesc('ready_at')
            ->first();
        $record = $candidates;
        if (
            ! $record instanceof ReportRunRecord
            || $record->snapshot_stale_at === null
            || $record->snapshot_generated_at === null
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $this->assertOwnerSnapshotReady($record, $code, $query);

        return new ReportSnapshotRef(
            $code,
            (string) $record->snapshot_id,
            $query->scope,
            new Sha256Hash((string) $record->definition_hash),
            (string) $record->formula_version,
            new Sha256Hash((string) $record->source_hash),
            DateTimeImmutable::createFromInterface($record->snapshot_generated_at),
            DateTimeImmutable::createFromInterface($record->snapshot_stale_at),
            array_merge($record->snapshot_watermarks ?? [], [
                'source_schema_version' => (string) $record->source_schema_version,
                'as_of' => $record->as_of->format(DATE_ATOM),
                'cohort_key' => ($record->filters ?? [])['cohort'] ?? null,
                'project_ids' => $query->scope->projectIds,
            ]),
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function assertOwnerSnapshotReady(
        ReportRunRecord $record,
        string $code,
        ReportQuery $query,
    ): void {
        $snapshot = match ($code) {
            'baseline_schedule_variance' => DB::table('baseline_schedule_variance_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->first(),
            'supply_reliability' => DB::table('supply_reliability_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('quality_status', 'complete')
                ->where('reconciliation_status', 'matched')
                ->where('gap_count', 0)
                ->first(),
            'quality_defect_flow' => DB::table('quality_defect_flow_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('gap_count', 0)
                ->where('unknown_count', 0)
                ->whereColumn('eligible_count', 'projected_count')
                ->first(),
            'safety_incident_actions' => DB::table('safety_incident_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('gap_count', 0)
                ->where('unknown_count', 0)
                ->whereColumn('eligible_count', 'projected_count')
                ->first(),
            default => null,
        };
        if (
            ! is_object($snapshot)
            || ! hash_equals((string) $snapshot->source_hash, (string) $record->source_hash)
            || ! hash_equals((string) $snapshot->formula_version, (string) $record->formula_version)
            || CarbonImmutable::parse((string) $snapshot->generated_at)
                ->notEqualTo(CarbonImmutable::instance($record->snapshot_generated_at))
            || $snapshot->stale_at === null
            || CarbonImmutable::parse((string) $snapshot->stale_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $snapshotAsOf = CarbonImmutable::parse((string) $snapshot->as_of);
        $sameAsOf = $code === 'baseline_schedule_variance'
            ? $snapshotAsOf->toDateString() === CarbonImmutable::instance($query->asOf)->toDateString()
            : $snapshotAsOf->equalTo(CarbonImmutable::instance($query->asOf));
        if (! $sameAsOf) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
    }
}
