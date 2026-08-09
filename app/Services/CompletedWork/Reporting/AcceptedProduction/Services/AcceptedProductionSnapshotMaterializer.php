<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionMetric;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseEntry;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AcceptedProductionSnapshotMaterializer
{
    private const FORMULA_VERSION = 'accepted_production.v1';

    private AcceptedProductionEventUniverse $universe;

    public function __construct(
        private AcceptedProductionFormula $formula,
        private ProductionAcceptanceRecognitionGrain $grain,
        ?AcceptedProductionEventUniverse $universe = null,
    ) {
        $this->universe = $universe ?? new AcceptedProductionEventUniverse;
    }

    public function materialize(
        ReportScope $scope,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('accepted_production_materialization_identity_invalid');
        }

        $progress->advance(10);
        $stream = $this->universe->stream($scope, $query);
        if ($stream->gapCount() !== 0) {
            throw new InvalidArgumentException('accepted_production_owner_history_unproven');
        }
        $watermark = $stream->eventWatermark;
        $ownerWatermark = $stream->ownerWatermark;
        $lineageFilter = AcceptedProductionLineageFilter::fromQuery($query)->canonicalIdentity();
        $hashContext = hash_init('sha256');
        hash_update($hashContext, '{"lineage_projection_version":2,"source":');
        $stream->updateSourceHash($hashContext);
        hash_update($hashContext, '}');
        $sourceHash = new Sha256Hash(hash_final($hashContext));
        $progress->advance(20);
        $existing = AcceptedProductionSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing !== null) {
            $progress->advance(100);

            return $this->reference($scope, $query, $existing);
        }

        $rowCount = 0;
        $totalsState = [];
        $eligibleCount = $stream->eligibleCount();
        foreach ($stream->entries() as $entry) {
            $progress->advanceProportion($rowCount, $eligibleCount, 20, 65);
            [, $metric] = $this->metric($entry);
            $this->accumulateTotal($totalsState, $metric);
            $rowCount++;
        }
        $totals = $this->finalizeTotals($totalsState);
        $progress->advance(70);

        try {
            return DB::transaction(function () use (
                $scope,
                $query,
                $sourceHash,
                $watermark,
                $ownerWatermark,
                $stream,
                $rowCount,
                $totals,
                $lineageFilter,
                $progress,
            ): ReportSnapshotRef {
                $snapshotId = (string) Str::ulid();
                $sourceRefs = [[
                    'source' => 'completed_work',
                    'snapshot_kind' => 'accepted_production',
                    'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                    'schema_version' => 'production_acceptance_events_v2',
                    'watermark' => 'event_'.$watermark,
                    'row_count' => $rowCount,
                    'hash' => $sourceHash->value,
                ], [
                    'source' => 'production_acceptance_history_boundary',
                    'snapshot_kind' => 'immutable_checkpoint',
                    'snapshot_id' => 'organization_'.$scope->organizationId,
                    'schema_version' => 'production_acceptance_history_boundary_v1',
                    'watermark' => 'checkpoint_'.$stream->historyBoundary->sourceHash,
                    'row_count' => 1,
                    'hash' => $stream->historyBoundary->sourceHash,
                ]];
                $snapshot = AcceptedProductionSnapshot::query()->create([
                    'id' => $snapshotId,
                    'organization_id' => $scope->organizationId,
                    'as_of' => $query->asOf,
                    'event_watermark' => $watermark,
                    'formula_version' => self::FORMULA_VERSION,
                    'definition_hash' => $query->definition->definitionHash->value,
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'generated_at' => now(),
                    'stale_at' => now()->addMinutes(15),
                    'watermarks' => [
                        'acceptance_events' => 'event_'.$watermark,
                        'acceptance_owners' => 'owner_'.$ownerWatermark,
                        'acceptance_owner_members' => 'member_'.$stream->ownerMemberWatermark,
                        'history_boundary' => 'checkpoint_'.$stream->historyBoundary->sourceHash,
                        'lineage_projection' => 'v2',
                    ],
                    'totals' => $totals,
                    'source_refs' => $sourceRefs,
                    'row_schema' => $this->rowSchema(),
                    'row_count' => $rowCount,
                ]);

                $rowBatch = [];
                foreach ($stream->entries() as $rowIndex => $entry) {
                    $progress->advanceProportion($rowIndex, $rowCount, 75, 98);
                    [$item, $metric] = $this->metric($entry);
                    $event = $item['event'];
                    $recognizedOn = $this->grain->day($event, $scope->timezone);
                    $rowKey = $this->grain->key($event, $scope->timezone);
                    $payload = [
                        'project_id' => (int) $event->project_id,
                        'performance_act_id' => (int) $event->performance_act_id,
                        'owner_version_id' => (int) $item['owner']['owner_version_id'],
                        'source_line_type' => (string) $event->source_line_type,
                        'source_line_id' => (int) $event->source_line_id,
                        'event_status' => (string) $event->event_type,
                        'work_id' => $event->work_id === null ? null : (int) $event->work_id,
                        'task_id' => $event->task_id === null ? null : (int) $event->task_id,
                        'wbs_code' => $event->wbs_code,
                        'zone' => $event->zone,
                        'contractor_id' => $event->contractor_id === null ? null : (int) $event->contractor_id,
                        'recognized_on' => $recognizedOn,
                        'planned_quantity' => $metric->plannedQuantity,
                        'reported_quantity' => $metric->reportedQuantity,
                        'accepted_quantity' => $metric->acceptedQuantity,
                        'accepted_plan_variance' => $metric->acceptedPlanVariance,
                        'reported_accepted_variance' => $metric->reportedAcceptedVariance,
                        'completion_ratio' => $metric->completionRatio,
                        'unit_dimension' => $metric->unitDimension,
                        'unit_code' => $metric->unitCode,
                        'conversion_version' => $metric->conversionVersion,
                        'currency' => $metric->currency,
                        'approved_rate_minor' => $item['fact']->approvedRateMinor,
                        'accepted_amount_minor' => $metric->acceptedAmountMinor,
                        'lineage_projection_version' => 2,
                        'acceptance_lineage' => $entry->lineage->canonicalIdentity(),
                        'acceptance_lineage_filter' => $lineageFilter,
                        'unknown_metrics' => $metric->acceptedAmountMinor === null ? ['accepted_amount_minor'] : [],
                    ];
                    $sourceRefs = [
                        [
                            'type' => 'performance_act',
                            'id' => (int) $event->performance_act_id,
                            'project_id' => (int) $event->project_id,
                        ],
                        ...array_map(
                            static fn (array $owner): array => [
                                'type' => 'production_acceptance_owner_version',
                                'id' => (int) $owner['id'],
                                'project_id' => (int) $event->project_id,
                                'source_hash' => (string) $owner['source_hash'],
                            ],
                            (array) $item['owner']['owner_versions'],
                        ),
                        ...($event->work_id === null ? [] : [[
                            'type' => 'completed_work',
                            'id' => (int) $event->work_id,
                            'project_id' => (int) $event->project_id,
                        ]]),
                        ...(array) $event->evidence_refs,
                        [
                            'type' => 'acceptance_event',
                            'id' => $entry->lineage->firstId,
                            'project_id' => (int) $event->project_id,
                            'lineage' => $entry->lineage->canonicalIdentity(),
                        ],
                    ];
                    $rowBatch[] = [
                        'organization_id' => $scope->organizationId,
                        'snapshot_id' => $snapshotId,
                        'row_key' => $rowKey,
                        'project_id' => (int) $event->project_id,
                        'performance_act_id' => (int) $event->performance_act_id,
                        'source_line_type' => (string) $event->source_line_type,
                        'source_line_id' => (int) $event->source_line_id,
                        'work_id' => $event->work_id,
                        'contractor_id' => $event->contractor_id,
                        'zone' => $event->zone,
                        'event_status' => (string) $event->event_type,
                        'recognized_on' => $recognizedOn,
                        'unit_dimension' => (string) $event->unit_dimension,
                        'unit_code' => (string) $event->unit_code,
                        'currency' => $event->currency,
                        'accepted_quantity' => $metric->acceptedQuantity,
                        'accepted_amount_minor' => $metric->acceptedAmountMinor,
                        'payload' => CanonicalJson::encode($payload),
                        'source_refs' => CanonicalJson::encode($sourceRefs),
                    ];
                    if (count($rowBatch) === 500) {
                        DB::table('accepted_production_rows')->insert($rowBatch);
                        $rowBatch = [];
                    }
                }
                if ($rowBatch !== []) {
                    DB::table('accepted_production_rows')->insert($rowBatch);
                }
                $progress->advance(99);

                return $this->reference($scope, $query, $snapshot);
            });
        } catch (QueryException $exception) {
            $existing = AcceptedProductionSnapshot::query()
                ->where('organization_id', $scope->organizationId)
                ->where('query_hash', $query->queryHash->value)
                ->where('source_hash', $sourceHash->value)
                ->first();
            if ($existing !== null) {
                return $this->reference($scope, $query, $existing);
            }

            throw new InvalidArgumentException('accepted_production_snapshot_conflict', 0, $exception);
        }
    }

    private function metric(AcceptedProductionUniverseEntry $entry): array
    {
        $event = $entry->latestEvent();
        if ($event === null) {
            throw new InvalidArgumentException('accepted_production_event_group_invalid');
        }
        $item = [
            'event' => $event,
            'owner' => $entry->candidate,
            'fact' => $entry->fact,
        ];

        return [$item, $this->formula->row($item['fact'])];
    }

    private function accumulateTotal(array &$groups, AcceptedProductionMetric $metric): void
    {
        $key = implode(':', [
            $metric->unitDimension,
            $metric->unitCode,
            $metric->conversionVersion,
            $metric->currency ?? 'NO_CURRENCY',
        ]);
        $groups[$key] ??= [
            'unit_dimension' => $metric->unitDimension,
            'unit_code' => $metric->unitCode,
            'conversion_version' => $metric->conversionVersion,
            'currency' => $metric->currency,
            'planned_quantity' => 0,
            'reported_quantity' => 0,
            'accepted_quantity' => 0,
            'accepted_amount_minor' => $metric->acceptedAmountMinor === null ? null : 0,
        ];
        $groups[$key]['planned_quantity'] += AcceptedProductionQuantity::scaled(
            $metric->plannedQuantity,
            'accepted_production_quantity_invalid',
        );
        $groups[$key]['reported_quantity'] += AcceptedProductionQuantity::scaled(
            $metric->reportedQuantity,
            'accepted_production_quantity_invalid',
        );
        $groups[$key]['accepted_quantity'] += AcceptedProductionQuantity::scaled(
            $metric->acceptedQuantity,
            'accepted_production_quantity_invalid',
        );
        if ($groups[$key]['accepted_amount_minor'] !== null) {
            if ($metric->acceptedAmountMinor === null) {
                $groups[$key]['accepted_amount_minor'] = null;
            } else {
                $groups[$key]['accepted_amount_minor'] += $metric->acceptedAmountMinor;
            }
        }
    }

    private function finalizeTotals(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['planned_quantity'] = AcceptedProductionQuantity::decimal($group['planned_quantity']);
            $group['reported_quantity'] = AcceptedProductionQuantity::decimal($group['reported_quantity']);
            $group['accepted_quantity'] = AcceptedProductionQuantity::decimal($group['accepted_quantity']);
        }
        unset($group);

        return [
            'groups' => array_values($groups),
            'unknown_metrics' => array_reduce(
                $groups,
                static fn (array $carry, array $group): array => $group['accepted_amount_minor'] === null ? ['accepted_amount_minor'] : $carry,
                [],
            ),
        ];
    }

    private function reference(
        ReportScope $scope,
        ReportQuery $query,
        AcceptedProductionSnapshot $snapshot,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            'accepted_production_progress',
            (string) $snapshot->id,
            $scope,
            $query->definition->definitionHash,
            self::FORMULA_VERSION,
            new Sha256Hash((string) $snapshot->source_hash),
            new DateTimeImmutable($snapshot->generated_at->format(DATE_ATOM)),
            $snapshot->stale_at === null ? null : new DateTimeImmutable($snapshot->stale_at->format(DATE_ATOM)),
            (array) $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function rowSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'recognized_on',
                'project_id',
                'wbs_code',
                'work_id',
                'performance_act_id',
                'source_line_type',
                'source_line_id',
                'planned_quantity',
                'reported_quantity',
                'accepted_quantity',
                'accepted_plan_variance',
                'reported_accepted_variance',
                'completion_ratio',
                'unit_dimension',
                'unit_code',
                'currency',
                'approved_rate_minor',
                'accepted_amount_minor',
                'event_status',
            ],
        );
    }
}
