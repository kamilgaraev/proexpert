<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Backfill;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyVersionWriter;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\WorkConstraintEventRecorder;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessBackfill implements ReportSourceBackfill
{
    public function __construct(
        private WorkConstraintEventRecorder $events,
        private LookaheadReadinessPolicyVersionWriter $policies,
    ) {}

    public function sourceCode(): string
    {
        return 'lookahead_readiness';
    }

    public function sourceSchemaVersion(): string
    {
        return 'lookahead_events_v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        $watermarkId = $this->assertContext($context, $cursor, $limit);
        $records = WorkConstraint::withTrashed()
            ->where('organization_id', $context->organizationId)
            ->whereIn('project_id', $context->projectIds)
            ->where('id', '>', $cursor->lastSourceId)
            ->where('id', '<=', $watermarkId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)
            ->map(static fn (WorkConstraint $constraint): array => [
                'created_at' => $constraint->created_at?->format(DATE_ATOM),
                'overridden_at' => $constraint->overridden_at?->format(DATE_ATOM),
                'project_id' => (int) $constraint->project_id,
                'resolved_at' => $constraint->resolved_at?->format(DATE_ATOM),
                'source_id' => (int) $constraint->id,
                'status' => (string) $constraint->status,
            ])
            ->values()
            ->all();
        $lastSourceId = $rows === []
            ? $cursor->lastSourceId
            : (int) $rows[array_key_last($rows)]['source_id'];
        $nextCursor = new ReportSourceBackfillCursor($lastSourceId, $context->sourceWatermark);
        $inputHash = hash('sha256', CanonicalJson::encode([
            'cursor' => $cursor->canonicalIdentity(),
            'rows' => $rows,
            'scope_hash' => $context->scopeHash,
        ]));

        return new ReportSourceBackfillBatch($rows, $nextCursor, $hasMore, count($rows), $inputHash);
    }

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult {
        $defaultPolicy = $this->policies->publish(
            LookaheadReadinessPolicyDefinition::default(
                organizationId: $context->organizationId,
                effectiveFrom: new DateTimeImmutable('2026-07-30T00:00:00+00:00'),
            ),
        );
        $projected = [];
        $gapCount = 0;
        foreach ($batch->rows as $row) {
            $constraint = WorkConstraint::withTrashed()
                ->where('organization_id', $context->organizationId)
                ->whereIn('project_id', $context->projectIds)
                ->find((int) ($row['source_id'] ?? 0));
            if ($constraint === null || $constraint->created_at === null) {
                $gapCount++;

                continue;
            }

            try {
                $eventIds = WorkConstraintTransitionEvent::query()
                    ->where('organization_id', $context->organizationId)
                    ->where('constraint_id', $constraint->id)
                    ->orderBy('event_version')
                    ->pluck('id')
                    ->map('intval')
                    ->all();
                if ($eventIds === []) {
                    $eventIds[] = (int) $this->events->record(
                        $constraint,
                        null,
                        'open',
                        $constraint->created_by_user_id === null
                            ? null
                            : (int) $constraint->created_by_user_id,
                        new DateTimeImmutable($constraint->created_at->format(DATE_ATOM)),
                    )->id;
                }
                if ($constraint->resolved_at !== null && (string) $constraint->status !== 'open') {
                    $eventIds[] = (int) $this->events->record(
                        $constraint,
                        'open',
                        (string) $constraint->status,
                        $constraint->resolved_by_user_id === null
                            ? null
                            : (int) $constraint->resolved_by_user_id,
                        new DateTimeImmutable($constraint->resolved_at->format(DATE_ATOM)),
                    )->id;
                } elseif ($constraint->overridden_at !== null && (string) $constraint->status !== 'open') {
                    $metadata = (array) $constraint->metadata;
                    $evidenceRef = $metadata['waiver_evidence_ref'] ?? null;
                    $waiverUntil = $metadata['waiver_until'] ?? null;
                    if (! is_string($evidenceRef)
                        || trim($evidenceRef) === ''
                        || ! is_string($waiverUntil)
                        || trim($waiverUntil) === ''
                    ) {
                        throw new InvalidArgumentException('lookahead_waiver_history_unproven');
                    }
                    $eventIds[] = (int) $this->events->record(
                        $constraint,
                        'open',
                        (string) $constraint->status,
                        $constraint->overridden_by_user_id === null
                            ? null
                            : (int) $constraint->overridden_by_user_id,
                        new DateTimeImmutable($constraint->overridden_at->format(DATE_ATOM)),
                        new DateTimeImmutable($waiverUntil),
                        $evidenceRef,
                    )->id;
                } elseif ((string) $constraint->status !== 'open') {
                    throw new InvalidArgumentException('lookahead_constraint_backfill_history_unproven');
                }
                $eventIds = array_values(array_unique($eventIds, SORT_NUMERIC));
                sort($eventIds, SORT_NUMERIC);
                $projected[] = [
                    'event_ids' => $eventIds,
                    'source_id' => (int) $constraint->id,
                ];
            } catch (InvalidArgumentException) {
                $gapCount++;
            }
        }

        return new ReportSourceBackfillResult(
            cursor: $batch->nextCursor,
            eligibleCount: $batch->eligibleCount,
            projectedCount: count($projected),
            gapCount: $gapCount,
            unknownCount: 0,
            inputHash: $batch->inputHash,
            outputHash: hash('sha256', CanonicalJson::encode([
                'default_policy_hash' => $defaultPolicy->sourceHash,
                'projected_constraints' => $projected,
            ])),
        );
    }

    private function assertContext(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): int {
        if ($context->reportCode !== $this->sourceCode()
            || $cursor->sourceWatermark !== $context->sourceWatermark
            || $limit < 1
            || $limit > 1000
            || preg_match('/^constraint:(\d+)$/D', $context->sourceWatermark, $matches) !== 1
        ) {
            throw new InvalidArgumentException('lookahead_backfill_context_invalid');
        }

        return (int) $matches[1];
    }
}
