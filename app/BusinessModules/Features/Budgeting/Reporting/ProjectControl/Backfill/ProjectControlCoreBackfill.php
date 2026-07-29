<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Backfill;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion;
use App\Models\ProjectSchedule;
use InvalidArgumentException;

final readonly class ProjectControlCoreBackfill implements ReportSourceBackfill
{
    public function sourceCode(): string
    {
        return 'project_evm_control';
    }

    public function sourceSchemaVersion(): string
    {
        return 'project_control_v1';
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
            ->whereNotNull('baseline_saved_at')
            ->where('id', '>', $cursor->lastSourceId)
            ->where('id', '<=', $watermarkId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'project_id', 'baseline_saved_at', 'baseline_saved_by_user_id']);
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)
            ->map(static fn (ProjectSchedule $schedule): array => [
                'approved_at' => $schedule->baseline_saved_at?->format(DATE_ATOM),
                'approved_by' => $schedule->baseline_saved_by_user_id,
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
            $schedule = ProjectSchedule::query()
                ->where('organization_id', $context->organizationId)
                ->whereIn('project_id', $context->projectIds)
                ->find((int) ($row['source_id'] ?? 0));
            if ($schedule === null
                || $schedule->baseline_saved_at === null
                || (int) $schedule->baseline_saved_by_user_id < 1
            ) {
                $gapCount++;
                continue;
            }
            $existing = ProjectControlBaselineVersion::query()
                ->where('organization_id', $context->organizationId)
                ->where('schedule_id', $schedule->id)
                ->orderByDesc('version_number')
                ->first();
            if ($existing === null) {
                $gapCount++;
                continue;
            }
            $projected[] = [
                'source_hash' => (string) $existing->source_hash,
                'source_id' => (int) $schedule->id,
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
            throw new InvalidArgumentException('project_control_backfill_context_invalid');
        }

        return (int) $matches[1];
    }
}
