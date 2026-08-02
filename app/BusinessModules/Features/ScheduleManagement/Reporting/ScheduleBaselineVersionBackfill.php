<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Models\ScheduleBaselineVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\ScheduleTaskStateVersion;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ScheduleBaselineVersionBackfill implements ReportSourceBackfill
{
    public function __construct(
        private ScheduleTaskStateRecorder $taskStates,
    ) {
    }

    public function sourceCode(): string
    {
        return 'baseline_schedule_variance';
    }

    public function sourceSchemaVersion(): string
    {
        return 'schedule_baseline_v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        $watermarkId = $this->assertContext($context, $cursor, $limit);
        $records = ProjectSchedule::query()
            ->where('organization_id', $context->organizationId)
            ->whereIn('project_id', $context->projectIds)
            ->where('id', '>', $cursor->lastSourceId)
            ->where('id', '<=', $watermarkId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'project_id', 'baseline_saved_at']);
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)
            ->map(static fn (ProjectSchedule $schedule): array => [
                'baseline_saved_at' => $schedule->baseline_saved_at?->format(DATE_ATOM),
                'project_id' => (int) $schedule->project_id,
                'source_id' => (int) $schedule->id,
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
        $projected = [];
        $gapCount = 0;
        foreach ($batch->rows as $row) {
            $scheduleId = (int) ($row['source_id'] ?? 0);
            $baseline = ScheduleBaselineVersion::query()
                ->where('organization_id', $context->organizationId)
                ->where('schedule_id', $scheduleId)
                ->orderByDesc('version')
                ->first();
            if ($baseline === null) {
                $gapCount++;
                continue;
            }
            $tasks = ScheduleTask::withTrashed()
                ->where('organization_id', $context->organizationId)
                ->where('schedule_id', $scheduleId)
                ->orderBy('id')
                ->get();
            $stateHashes = [];
            $complete = true;
            foreach ($tasks as $task) {
                $state = ScheduleTaskStateVersion::query()
                    ->where('organization_id', $context->organizationId)
                    ->where('task_id', $task->id)
                    ->orderByDesc('version')
                    ->first();
                if ($state === null) {
                    if ($task->created_at === null
                        || $task->updated_at === null
                        || $task->deleted_at !== null
                        || !$task->created_at->equalTo($task->updated_at)
                    ) {
                        $complete = false;
                        break;
                    }
                    $state = $this->taskStates->capture(
                        $task,
                        new DateTimeImmutable($task->created_at->format(DATE_ATOM)),
                        'historical_created',
                        true,
                    );
                }
                $stateHashes[] = (string) $state->source_hash;
            }
            if (!$complete) {
                $gapCount++;
                continue;
            }
            $projected[] = [
                'baseline_hash' => (string) $baseline->source_hash,
                'source_id' => $scheduleId,
                'task_state_hashes' => $stateHashes,
            ];
        }

        return new ReportSourceBackfillResult(
            cursor: $batch->nextCursor,
            eligibleCount: $batch->eligibleCount,
            projectedCount: count($projected),
            gapCount: $gapCount,
            unknownCount: 0,
            inputHash: $batch->inputHash,
            outputHash: hash('sha256', CanonicalJson::encode($projected)),
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
            || preg_match('/^schedule:(\d+)$/D', $context->sourceWatermark, $matches) !== 1
        ) {
            throw new InvalidArgumentException('schedule_baseline_backfill_context_invalid');
        }

        return (int) $matches[1];
    }
}
