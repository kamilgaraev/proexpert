<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionRow;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AcceptedProductionSnapshotMaterializer
{
    private const FORMULA_VERSION = 'accepted_production.v1';

    private AcceptedProductionLifecycleCompleteness $completeness;

    private AcceptedProductionEventUniverse $universe;

    public function __construct(
        private AcceptedProductionFormula $formula,
        private ProductionAcceptanceRecognitionGrain $grain,
        ?AcceptedProductionEventUniverse $universe = null,
        ?AcceptedProductionLifecycleCompleteness $completeness = null,
    ) {
        $this->universe = $universe ?? new AcceptedProductionEventUniverse;
        $this->completeness = $completeness ?? new AcceptedProductionLifecycleCompleteness;
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('accepted_production_materialization_identity_invalid');
        }

        $universe = $this->universe->resolve($scope, $query);
        $events = $universe['events'];
        $this->completeness->assertComplete($scope, $query->asOf, $events, $universe);
        $watermark = (int) ($events->max('id') ?? 0);
        $ownerWatermark = (int) collect($universe['candidates'])->max('owner_version_id');
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'event_watermark' => $watermark,
            'events' => $events->map(static fn (ProductionAcceptanceEvent $event): array => [
                'accepted_quantity_delta' => (string) $event->accepted_quantity_delta,
                'approved_rate_minor' => $event->approved_rate_minor,
                'currency' => $event->currency,
                'currency_source' => $event->currency_source,
                'event_type' => (string) $event->event_type,
                'id' => (int) $event->id,
                'performance_act_id' => (int) $event->performance_act_id,
                'recognized_at' => $event->recognized_at->format(DATE_ATOM),
                'source_hash' => (string) $event->source_hash,
                'source_line_id' => (int) $event->source_line_id,
                'source_line_type' => (string) $event->source_line_type,
                'transition_version' => (int) $event->transition_version,
            ])->all(),
            'owner_candidates' => $universe['candidates'],
        ])));
        $existing = AcceptedProductionSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing !== null) {
            return $this->reference($scope, $query, $existing);
        }

        $candidates = collect($universe['candidates'])->keyBy(static fn (array $candidate): string => implode(':', [
            (int) $candidate['performance_act_id'],
            (string) $candidate['source_line_type'],
            (int) $candidate['source_line_id'],
        ]));
        $facts = $events
            ->groupBy(fn (ProductionAcceptanceEvent $event): string => $this->grain->key($event))
            ->map(function (Collection $lineEvents) use ($candidates): array {
                $event = $lineEvents->first();
                if (! $event instanceof ProductionAcceptanceEvent) {
                    throw new InvalidArgumentException('accepted_production_event_group_invalid');
                }
                $candidate = $candidates->get(implode(':', [
                    (int) $event->performance_act_id,
                    (string) $event->source_line_type,
                    (int) $event->source_line_id,
                ]));
                if (! is_array($candidate)) {
                    throw new InvalidArgumentException('accepted_production_owner_candidate_missing');
                }

                return $this->fact($lineEvents, $candidate);
            })
            ->values();

        try {
            return DB::transaction(function () use (
                $scope,
                $query,
                $sourceHash,
                $watermark,
                $ownerWatermark,
                $facts,
            ): ReportSnapshotRef {
                $snapshotId = (string) Str::ulid();
                $rows = $facts->map(function (array $item): array {
                    $metric = $this->formula->row($item['fact']);

                    return [$item, $metric];
                });
                $totals = $this->totals($rows);
                $sourceRefs = [[
                    'source' => 'completed_work',
                    'snapshot_kind' => 'accepted_production',
                    'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                    'schema_version' => 'production_acceptance_events_v1',
                    'watermark' => 'event_'.$watermark,
                    'row_count' => $rows->count(),
                    'hash' => $sourceHash->value,
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
                    ],
                    'totals' => $totals,
                    'source_refs' => $sourceRefs,
                    'row_schema' => $this->rowSchema(),
                    'row_count' => $rows->count(),
                ]);

                foreach ($rows as [$item, $metric]) {
                    $event = $item['event'];
                    $rowKey = $this->grain->key($event);
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
                        'recognized_on' => $event->recognized_at->format('Y-m-d'),
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
                        'unknown_metrics' => $metric->acceptedAmountMinor === null ? ['accepted_amount_minor'] : [],
                    ];
                    AcceptedProductionRow::query()->create([
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
                        'recognized_on' => $event->recognized_at->format('Y-m-d'),
                        'unit_dimension' => (string) $event->unit_dimension,
                        'unit_code' => (string) $event->unit_code,
                        'currency' => $event->currency,
                        'accepted_quantity' => $metric->acceptedQuantity,
                        'accepted_amount_minor' => $metric->acceptedAmountMinor,
                        'payload' => $payload,
                        'source_refs' => [
                            [
                                'type' => 'performance_act',
                                'id' => (int) $event->performance_act_id,
                                'project_id' => (int) $event->project_id,
                            ],
                            [
                                'type' => 'production_acceptance_owner_version',
                                'id' => (int) $item['owner']['owner_version_id'],
                                'project_id' => (int) $event->project_id,
                                'source_hash' => (string) $item['owner']['owner_source_hash'],
                            ],
                            ...($event->work_id === null ? [] : [[
                                'type' => 'completed_work',
                                'id' => (int) $event->work_id,
                                'project_id' => (int) $event->project_id,
                            ]]),
                            ...(array) $event->evidence_refs,
                            [
                                'type' => 'acceptance_event',
                                'ids' => $item['event_ids'],
                                'project_id' => (int) $event->project_id,
                            ],
                        ],
                    ]);
                }

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

    private function fact(Collection $events, array $owner): array
    {
        $first = $events->first();
        $last = $events->last();
        if (! $first instanceof ProductionAcceptanceEvent || ! $last instanceof ProductionAcceptanceEvent) {
            throw new InvalidArgumentException('accepted_production_event_group_invalid');
        }
        foreach ($events as $event) {
            if ($event->unit_dimension !== $first->unit_dimension
                || $event->unit_code !== $first->unit_code
                || $event->conversion_version !== $first->conversion_version
                || $event->currency !== $first->currency
                || $event->currency_source !== $first->currency_source
                || $event->approved_rate_minor !== $first->approved_rate_minor
            ) {
                throw new InvalidArgumentException('accepted_production_event_identity_changed');
            }
            if ($event->approved_rate_minor === null
                || $event->currency === null
                || $event->currency_source === null
            ) {
                throw new InvalidArgumentException('accepted_production_rate_identity_missing');
            }
        }
        $accepted = $events->reduce(
            fn (int $carry, ProductionAcceptanceEvent $event): int => $carry + $this->scaled((string) $event->accepted_quantity_delta),
            0,
        );

        return [
            'event' => $last,
            'event_ids' => $events->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            'owner' => $owner,
            'fact' => new ProductionAcceptanceFact(
                plannedQuantity: $this->decimal($this->scaled((string) $first->planned_quantity)),
                reportedQuantity: $this->decimal($this->scaled((string) $first->reported_quantity)),
                acceptedQuantityDelta: $this->decimal($accepted),
                unitDimension: (string) $first->unit_dimension,
                unitCode: (string) $first->unit_code,
                conversionVersion: (string) $first->conversion_version,
                approvedRateMinor: $first->approved_rate_minor === null
                    ? null
                    : (int) $first->approved_rate_minor,
                currency: $first->currency,
                currencySource: $first->currency_source,
            ),
        ];
    }

    private function totals(Collection $rows): array
    {
        $groups = [];
        foreach ($rows as [, $metric]) {
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
            $groups[$key]['planned_quantity'] += $this->scaled($metric->plannedQuantity);
            $groups[$key]['reported_quantity'] += $this->scaled($metric->reportedQuantity);
            $groups[$key]['accepted_quantity'] += $this->scaled($metric->acceptedQuantity);
            if ($groups[$key]['accepted_amount_minor'] !== null) {
                if ($metric->acceptedAmountMinor === null) {
                    $groups[$key]['accepted_amount_minor'] = null;
                } else {
                    $groups[$key]['accepted_amount_minor'] += $metric->acceptedAmountMinor;
                }
            }
        }
        foreach ($groups as &$group) {
            $group['planned_quantity'] = $this->decimal($group['planned_quantity']);
            $group['reported_quantity'] = $this->decimal($group['reported_quantity']);
            $group['accepted_quantity'] = $this->decimal($group['accepted_quantity']);
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

    private function scaled(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/D', $unsigned, $matches) !== 1) {
            throw new InvalidArgumentException('accepted_production_quantity_invalid');
        }
        $scaled = ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');

        return $negative ? -$scaled : $scaled;
    }

    private function decimal(int $scaled): string
    {
        $absolute = abs($scaled);
        $value = intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);

        return $scaled < 0 ? '-'.$value : $value;
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
                'planned_quantity',
                'reported_quantity',
                'accepted_quantity',
                'accepted_plan_variance',
                'reported_accepted_variance',
                'completion_ratio',
                'unit_code',
                'currency',
                'approved_rate_minor',
                'accepted_amount_minor',
                'event_status',
            ],
        );
    }
}
