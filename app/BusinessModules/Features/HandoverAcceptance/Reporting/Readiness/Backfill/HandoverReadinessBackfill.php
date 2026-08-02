<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Backfill;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverEvidenceEvent;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverEvidenceEventRecorder;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class HandoverReadinessBackfill implements ReportSourceBackfill
{
    public function __construct(private HandoverEvidenceEventRecorder $events)
    {
    }

    public function sourceCode(): string
    {
        return 'handover_evidence_events';
    }

    public function sourceSchemaVersion(): string
    {
        return 'handover-readiness.v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        $this->assertLimit($limit);
        if (
            $cursor->position !== []
            && (
                array_keys($cursor->position) !== ['acceptance_scope_id']
                || !is_int($cursor->position['acceptance_scope_id'])
                || $cursor->position['acceptance_scope_id'] < 0
            )
        ) {
            throw new InvalidArgumentException('handover_backfill_cursor_invalid');
        }
        $afterId = (int) ($cursor->position['acceptance_scope_id'] ?? 0);
        $ids = AcceptanceScope::query()
            ->where('organization_id', $context->organizationId)
            ->where('id', '>', $afterId)
            ->where('created_at', '<=', $context->asOf)
            ->when(
                $context->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
            )
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $lastId = $ids === [] ? $afterId : $ids[array_key_last($ids)];
        $to = new ReportSourceBackfillCursor(['acceptance_scope_id' => $lastId]);
        $final = count($ids) < $limit;

        return new ReportSourceBackfillBatch(
            $cursor,
            $to,
            $ids,
            $final,
            hash('sha256', CanonicalJson::encode([
                'as_of' => $context->asOf->toISOString(),
                'final' => $final,
                'from' => $cursor->position,
                'organization_id' => $context->organizationId,
                'scope_ids' => $ids,
                'source_watermark' => $context->sourceWatermark,
                'to' => $to->position,
            ])),
        );
    }

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult {
        $this->assertBatch($context, $batch);
        $scopes = AcceptanceScope::query()
            ->where('organization_id', $context->organizationId)
            ->whereIn('id', $batch->sourceKeys)
            ->where('created_at', '<=', $context->asOf)
            ->when(
                $context->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
            )
            ->with([
                'checklists.items',
                'findings.qualityDefect',
                'signoffs',
                'handoverPackage.documents',
            ])
            ->orderBy('id')
            ->get();
        $projection = [];
        $eligible = 0;
        $projected = 0;
        $unknown = count($batch->sourceKeys) - $scopes->count();
        $eligible = $unknown;

        foreach ($scopes as $scope) {
            $facts = $this->facts($scope, $context->asOf);
            $eligible += count($facts['events']) + $facts['unknown_count'];
            $unknown += $facts['unknown_count'];
            foreach ($facts['events'] as $fact) {
                try {
                    $existing = HandoverEvidenceEvent::query()
                        ->where('organization_id', $context->organizationId)
                        ->where('acceptance_scope_id', (int) $scope->id)
                        ->where('event_type', $fact['event_type'])
                        ->where('source_type', $fact['source_type'])
                        ->where('source_id', $fact['source_id'])
                        ->where('occurred_at', $fact['occurred_at'])
                        ->first();
                    $event = $existing ?? $this->events->record(
                        $scope,
                        $fact['event_type'],
                        $fact['source_type'],
                        $fact['source_id'],
                        $fact['occurred_at'],
                        $fact['actor_id'],
                    );
                    $projection[] = [
                        'event_id' => (string) $event->event_id,
                        'evidence_hash' => (string) $event->evidence_hash,
                    ];
                    $projected++;
                } catch (Throwable) {
                    $unknown++;
                }
            }
        }
        $gap = max(0, $eligible - $projected - $unknown);

        return new ReportSourceBackfillResult(
            $batch->to,
            $eligible,
            $projected,
            $gap,
            $unknown,
            hash('sha256', CanonicalJson::encode($projection)),
            $batch->final,
        );
    }

    private function facts(AcceptanceScope $scope, CarbonImmutable $asOf): array
    {
        $events = [];
        $unknown = 0;
        foreach ($scope->checklists as $checklist) {
            foreach ($checklist->items as $item) {
                if (
                    $item->created_at !== null
                    && CarbonImmutable::instance($item->created_at) > $asOf
                ) {
                    continue;
                }
                if ($item->status === 'pending') {
                    continue;
                }
                if ($item->code === null || $item->reviewed_at === null) {
                    $unknown++;
                    continue;
                }
                if (CarbonImmutable::instance($item->reviewed_at) > $asOf) {
                    continue;
                }
                $events[] = [
                    'event_type' => 'checklist_reviewed',
                    'source_type' => 'acceptance_checklist_item',
                    'source_id' => (int) $item->id,
                    'occurred_at' => CarbonImmutable::instance($item->reviewed_at),
                    'actor_id' => $item->reviewed_by_user_id === null ? null : (int) $item->reviewed_by_user_id,
                ];
            }
        }
        foreach ($scope->findings as $finding) {
            if (CarbonImmutable::instance($finding->created_at) > $asOf) {
                continue;
            }
            $events[] = [
                'event_type' => 'finding_opened',
                'source_type' => 'acceptance_finding',
                'source_id' => (int) $finding->id,
                'occurred_at' => CarbonImmutable::instance($finding->created_at),
                'actor_id' => (int) $finding->created_by_user_id,
            ];
            if ($finding->qualityDefect !== null) {
                $events[] = [
                    'event_type' => 'blocker_opened',
                    'source_type' => 'quality_defect',
                    'source_id' => (int) $finding->qualityDefect->id,
                    'occurred_at' => CarbonImmutable::instance($finding->qualityDefect->created_at),
                    'actor_id' => $finding->qualityDefect->created_by === null
                        ? null
                        : (int) $finding->qualityDefect->created_by,
                ];
                if (
                    $finding->qualityDefect->resolved_at !== null
                    && CarbonImmutable::instance($finding->qualityDefect->resolved_at) <= $asOf
                ) {
                    $events[] = [
                        'event_type' => 'blocker_resolved',
                        'source_type' => 'quality_defect',
                        'source_id' => (int) $finding->qualityDefect->id,
                        'occurred_at' => CarbonImmutable::instance($finding->qualityDefect->resolved_at),
                        'actor_id' => null,
                    ];
                }
            }
            if (
                $finding->resolved_at !== null
                && CarbonImmutable::instance($finding->resolved_at) <= $asOf
            ) {
                $events[] = [
                    'event_type' => 'finding_resolved',
                    'source_type' => 'acceptance_finding',
                    'source_id' => (int) $finding->id,
                    'occurred_at' => CarbonImmutable::instance($finding->resolved_at),
                    'actor_id' => $finding->resolved_by_user_id === null
                        ? null
                        : (int) $finding->resolved_by_user_id,
                ];
            } elseif ($finding->status === 'resolved') {
                $unknown++;
            }
        }
        foreach ($scope->handoverPackage?->documents ?? [] as $document) {
            if (CarbonImmutable::instance($document->created_at) > $asOf) {
                continue;
            }
            if (
                $document->approved_at !== null
                && CarbonImmutable::instance($document->approved_at) <= $asOf
            ) {
                $events[] = [
                    'event_type' => 'document_approved',
                    'source_type' => 'handover_document',
                    'source_id' => (int) $document->id,
                    'occurred_at' => CarbonImmutable::instance($document->approved_at),
                    'actor_id' => $document->approved_by_user_id === null
                        ? null
                        : (int) $document->approved_by_user_id,
                ];
            } elseif ($document->status === 'approved' && $document->updated_at <= $asOf) {
                $unknown++;
            } elseif (CarbonImmutable::instance($document->updated_at) > $asOf) {
                $unknown++;
            }
        }
        foreach ([
            ['accepted_at', 'scope_accepted'],
            ['handed_over_at', 'scope_handed_over'],
            ['reopened_at', 'scope_reopened'],
        ] as [$attribute, $eventType]) {
            if ($scope->getAttribute($attribute) !== null) {
                $events[] = [
                    'event_type' => $eventType,
                    'source_type' => 'acceptance_scope',
                    'source_id' => (int) $scope->id,
                    'occurred_at' => CarbonImmutable::instance($scope->getAttribute($attribute)),
                    'actor_id' => null,
                ];
            }
        }
        $external = $this->externalBlockerFacts($scope, $asOf);
        $events = [...$events, ...$external['events']];
        $unknown += $external['unknown_count'];
        $events = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['occurred_at'] <= $asOf,
        ));
        usort(
            $events,
            static fn (array $left, array $right): int =>
                $left['occurred_at'] <=> $right['occurred_at']
                ?: strcmp($left['event_type'], $right['event_type']),
        );

        return ['events' => $events, 'unknown_count' => $unknown];
    }

    private function externalBlockerFacts(AcceptanceScope $scope, CarbonImmutable $asOf): array
    {
        $events = [];
        $unknown = 0;
        $findingDefectIds = $scope->findings
            ->pluck('quality_defect_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        foreach (
            QualityDefect::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->where('metadata->source->acceptance_scope_id', (int) $scope->id)
                ->when(
                    $findingDefectIds !== [],
                    fn ($builder) => $builder->whereNotIn('id', $findingDefectIds),
                )
                ->where('created_at', '<=', $asOf)
                ->get() as $defect
        ) {
            $events[] = $this->blockerFact(
                'blocker_opened',
                'quality_defect',
                (int) $defect->id,
                CarbonImmutable::instance($defect->created_at),
                $defect->created_by === null ? null : (int) $defect->created_by,
            );
            if ($defect->resolved_at !== null && CarbonImmutable::instance($defect->resolved_at) <= $asOf) {
                $events[] = $this->blockerFact(
                    'blocker_resolved',
                    'quality_defect',
                    (int) $defect->id,
                    CarbonImmutable::instance($defect->resolved_at),
                    null,
                );
            } elseif ($defect->status === QualityDefectStatusEnum::RESOLVED) {
                $unknown++;
            }
        }
        foreach (
            ChangeManagementRfi::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->where('metadata->acceptance_scope_id', (int) $scope->id)
                ->where('created_at', '<=', $asOf)
                ->get() as $rfi
        ) {
            $events[] = $this->blockerFact(
                'blocker_opened',
                'rfi',
                (int) $rfi->id,
                CarbonImmutable::instance($rfi->created_at),
                $rfi->created_by_user_id === null ? null : (int) $rfi->created_by_user_id,
            );
            if ($rfi->closed_at !== null && CarbonImmutable::instance($rfi->closed_at) <= $asOf) {
                $events[] = $this->blockerFact(
                    'blocker_resolved',
                    'rfi',
                    (int) $rfi->id,
                    CarbonImmutable::instance($rfi->closed_at),
                    null,
                );
            } elseif ($rfi->status === 'closed') {
                $unknown++;
            }
        }
        foreach (
            WorkConstraint::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->where('metadata->acceptance_scope_id', (int) $scope->id)
                ->where('created_at', '<=', $asOf)
                ->get() as $constraint
        ) {
            $events[] = $this->blockerFact(
                'blocker_opened',
                'constraint',
                (int) $constraint->id,
                CarbonImmutable::instance($constraint->created_at),
                $constraint->created_by_user_id === null ? null : (int) $constraint->created_by_user_id,
            );
            if (
                $constraint->resolved_at !== null
                && CarbonImmutable::instance($constraint->resolved_at) <= $asOf
            ) {
                $events[] = $this->blockerFact(
                    'blocker_resolved',
                    'constraint',
                    (int) $constraint->id,
                    CarbonImmutable::instance($constraint->resolved_at),
                    $constraint->resolved_by_user_id === null
                        ? null
                        : (int) $constraint->resolved_by_user_id,
                );
            } elseif ($constraint->status === 'resolved') {
                $unknown++;
            }
        }
        foreach (
            ChangeRequest::query()
                ->where('organization_id', (int) $scope->organization_id)
                ->where('project_id', (int) $scope->project_id)
                ->whereJsonContains('linked_entities', [[
                    'type' => 'acceptance_scope',
                    'id' => (int) $scope->id,
                ]])
                ->where('created_at', '<=', $asOf)
                ->get() as $change
        ) {
            $events[] = $this->blockerFact(
                'blocker_opened',
                'change',
                (int) $change->id,
                CarbonImmutable::instance($change->created_at),
                $change->created_by_user_id === null ? null : (int) $change->created_by_user_id,
            );
            $resolvedAt = collect([
                $change->implemented_at,
                $change->closed_at,
                $change->rejected_at,
                $change->cancelled_at,
            ])
                ->filter()
                ->map(static fn (mixed $value): CarbonImmutable => CarbonImmutable::instance($value))
                ->filter(static fn (CarbonImmutable $value): bool => $value <= $asOf)
                ->sort()
                ->first();
            if ($resolvedAt instanceof CarbonImmutable) {
                $events[] = $this->blockerFact(
                    'blocker_resolved',
                    'change',
                    (int) $change->id,
                    $resolvedAt,
                    null,
                );
            } elseif (in_array($change->status, ['implemented', 'closed', 'rejected', 'cancelled'], true)) {
                $unknown++;
            }
        }

        return ['events' => $events, 'unknown_count' => $unknown];
    }

    private function blockerFact(
        string $eventType,
        string $sourceType,
        int $sourceId,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): array {
        return [
            'event_type' => $eventType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'occurred_at' => $occurredAt,
            'actor_id' => $actorId,
        ];
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('handover_backfill_limit_invalid');
        }
    }

    private function assertBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): void {
        foreach ($batch->sourceKeys as $sourceKey) {
            if (!is_int($sourceKey) || $sourceKey < 1) {
                throw new InvalidArgumentException('handover_backfill_batch_invalid');
            }
        }
        $expected = hash('sha256', CanonicalJson::encode([
            'as_of' => $context->asOf->toISOString(),
            'final' => $batch->final,
            'from' => $batch->from->position,
            'organization_id' => $context->organizationId,
            'scope_ids' => $batch->sourceKeys,
            'source_watermark' => $context->sourceWatermark,
            'to' => $batch->to->position,
        ]));
        $afterId = (int) ($batch->from->position['acceptance_scope_id'] ?? 0);
        $lastId = $batch->sourceKeys === []
            ? $afterId
            : $batch->sourceKeys[array_key_last($batch->sourceKeys)];
        $ordered = $batch->sourceKeys;
        sort($ordered, SORT_NUMERIC);
        if (
            !hash_equals($expected, $batch->inputHash)
            || (int) ($batch->to->position['acceptance_scope_id'] ?? -1) !== $lastId
            || $batch->sourceKeys !== array_values(array_unique($batch->sourceKeys))
            || $batch->sourceKeys !== $ordered
            || $batch->sourceKeys !== array_values(array_filter(
                $batch->sourceKeys,
                static fn (int $id): bool => $id > $afterId,
            ))
        ) {
            throw new InvalidArgumentException('handover_backfill_batch_invalid');
        }
    }
}
