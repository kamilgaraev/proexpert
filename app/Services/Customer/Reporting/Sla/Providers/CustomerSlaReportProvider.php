<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Providers;

use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaRow;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaSnapshot;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CustomerSlaReportProvider implements ReportDataProvider
{
    public function __construct(
        private CustomerSlaSnapshotMaterializer $materializer,
        private CanonicalReportSourceHashBuilder $identities,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $progress->advance(10);
        $provisional = $this->materializer->materialize($context, $query);
        $progress->advance(100);
        $canonical = $this->identities->build(
            $query,
            $provisional,
            $this->result($context, $provisional),
        );

        return new ReportSnapshotRef(
            kind: $provisional->kind,
            id: $provisional->id,
            scope: $provisional->scope,
            definitionHash: $provisional->definitionHash,
            formulaVersion: $provisional->formulaVersion,
            sourceHash: $canonical,
            generatedAt: $provisional->generatedAt,
            staleAt: $provisional->staleAt,
            watermarks: $provisional->watermarks,
            classification: $provisional->classification,
            seal: $provisional->seal,
            materializedSourceHash: $provisional->materializedSourceHash,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        if (
            $snapshot->kind !== 'customer_sla'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
        ) {
            throw new InvalidArgumentException('customer_sla_snapshot_invalid');
        }
        $record = CustomerSlaSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        $rows = CustomerSlaRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $rowCount = (int) $record->row_count;
        $knownActorSides = (clone $rows)->where('actor_side_complete', true)->count();
        $overdue = (clone $rows)->where(static function ($builder): void {
            $builder->where('first_response_breached', true)->orWhere('resolution_breached', true);
        })->count();
        $firstResponseBreached = (clone $rows)->where('first_response_breached', true)->count();
        $resolutionBreached = (clone $rows)->where('resolution_breached', true)->count();
        $firstResponseKnown = (clone $rows)->whereNotNull('first_response_seconds')->count();
        $resolutionKnown = (clone $rows)->whereNotNull('resolution_seconds')->count();
        $qualityStatus = $knownActorSides === $rowCount
            ? ReportQualityStatus::COMPLETE
            : ReportQualityStatus::PARTIAL;

        return new ReportResult(
            new ReportResultMetadata(
                $snapshot,
                $rowCount,
                DateTimeImmutable::createFromInterface($record->generated_at),
                $record->stale_at === null ? null : DateTimeImmutable::createFromInterface($record->stale_at),
            ),
            [
                'workflow_count' => $rowCount,
                'overdue_count' => $overdue,
                'first_response_breached_count' => $firstResponseBreached,
                'resolution_breached_count' => $resolutionBreached,
                'first_response_known_count' => $firstResponseKnown,
                'resolution_known_count' => $resolutionKnown,
                'actor_side_unknown_count' => $rowCount - $knownActorSides,
            ],
            $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable()
                ? ReportFreshnessStatus::STALE
                : ReportFreshnessStatus::FRESH,
            new ReportQuality(
                $qualityStatus,
                new ReportCoverage(
                    (string) $knownActorSides,
                    (string) $rowCount,
                    $rowCount === 0 ? null : bcdiv((string) $knownActorSides, (string) $rowCount, 8),
                ),
                [],
                $rowCount - $knownActorSides,
                $knownActorSides === $rowCount
                    ? ReportReconciliationStatus::MATCHED
                    : ReportReconciliationStatus::MISMATCH,
                $knownActorSides === $rowCount ? [] : ['actor_side'],
                [],
            ),
            new ReportProvenance(
                'customer_workflow',
                [new ReportSourceRef(
                    'customer_workflow',
                    'customer_sla_events',
                    'snapshot_'.strtolower($snapshot->id),
                    'customer_sla_v1',
                    'event_'.(string) ($record->watermarks['last_event_id'] ?? 0),
                    $rowCount,
                    new Sha256Hash((string) $record->source_hash),
                )],
                $snapshot->sourceHash,
                null,
            ),
            [
                ['id' => 'row_key', 'type' => 'string'],
                ['id' => 'project_id', 'type' => 'integer'],
                ['id' => 'customer_organization_id', 'type' => 'integer'],
                ['id' => 'workflow_type', 'type' => 'string'],
                ['id' => 'workflow_id', 'type' => 'integer'],
                ['id' => 'priority', 'type' => 'string'],
                ['id' => 'status', 'type' => 'string'],
                ['id' => 'opened_at', 'type' => 'datetime'],
                ['id' => 'first_response_seconds', 'type' => 'integer'],
                ['id' => 'resolution_seconds', 'type' => 'integer'],
                ['id' => 'open_aging_seconds', 'type' => 'integer'],
                ['id' => 'first_response_breached', 'type' => 'boolean'],
                ['id' => 'resolution_breached', 'type' => 'boolean'],
                ['id' => 'actor_side_complete', 'type' => 'boolean'],
            ],
            ['admin_only' => true, 'drill_down' => true, 'export_formats' => ['csv', 'xlsx']],
        );
    }
}
