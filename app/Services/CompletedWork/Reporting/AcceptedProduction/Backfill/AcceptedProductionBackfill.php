<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Backfill;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionBackfillReconciler;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AcceptedProductionBackfill implements ReportSourceBackfill
{
    public function __construct(
        private AcceptedProductionBackfillReconciler $reconciler,
    ) {}

    public function sourceCode(): string
    {
        return 'accepted_production_progress';
    }

    public function sourceSchemaVersion(): string
    {
        return 'production_acceptance_events_v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        $watermarkId = $this->assertContext($context, $cursor, $limit);
        $records = ContractPerformanceAct::query()
            ->whereIn('project_id', $context->projectIds)
            ->whereHas('contract', fn ($builder) => $builder->where('organization_id', $context->organizationId))
            ->where(function ($builder): void {
                $builder
                    ->where('is_approved', true)
                    ->orWhereIn('status', [
                        ContractPerformanceAct::STATUS_APPROVED,
                        ContractPerformanceAct::STATUS_SIGNED,
                    ]);
            })
            ->where('id', '>', $cursor->lastSourceId)
            ->where('id', '<=', $watermarkId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'project_id', 'signed_at', 'approval_date']);
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)
            ->map(static fn (ContractPerformanceAct $act): array => [
                'project_id' => (int) $act->project_id,
                'recognized_at' => $act->signed_at?->format(DATE_ATOM)
                    ?? $act->approval_date?->toImmutable()->startOfDay()->format(DATE_ATOM),
                'recognition_source' => $act->signed_at !== null
                    ? 'signed_at'
                    : ($act->approval_date !== null ? 'approval_date' : null),
                'source_id' => (int) $act->id,
            ])
            ->values()
            ->all();
        $lastSourceId = $rows === []
            ? $cursor->lastSourceId
            : (int) $rows[array_key_last($rows)]['source_id'];
        $nextCursor = new ReportSourceBackfillCursor($lastSourceId, $context->sourceWatermark);
        $inputHash = hash('sha256', CanonicalJson::encode([
            'context' => [
                $context->organizationId,
                $context->projectIds,
                $context->scopeHash,
                $context->sourceWatermark,
            ],
            'cursor' => $cursor->canonicalIdentity(),
            'rows' => $rows,
        ]));

        return new ReportSourceBackfillBatch($rows, $nextCursor, $hasMore, count($rows), $inputHash);
    }

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult {
        $projected = [];
        $gaps = 0;
        foreach ($batch->rows as $row) {
            if (! is_array($row)
                || ! isset($row['source_id'], $row['project_id'])
                || ! in_array((int) $row['project_id'], $context->projectIds, true)
            ) {
                throw new InvalidArgumentException('accepted_production_backfill_batch_invalid');
            }
            if (! is_string($row['recognized_at'] ?? null)
                || trim($row['recognized_at']) === ''
                || ! in_array($row['recognition_source'] ?? null, ['signed_at', 'approval_date'], true)
            ) {
                $gaps++;

                continue;
            }
            $act = ContractPerformanceAct::query()
                ->with('contract')
                ->whereKey((int) $row['source_id'])
                ->whereIn('project_id', $context->projectIds)
                ->first();
            if ($act === null) {
                $gaps++;

                continue;
            }

            try {
                $reconciliation = $this->reconciler->reconcile(
                    $act,
                    CarbonImmutable::parse($row['recognized_at']),
                );
                if (! $reconciliation['projected']) {
                    $gaps++;

                    continue;
                }
                $projected[] = [
                    'event_ids' => $reconciliation['event_ids'],
                    'source_id' => (int) $act->id,
                ];
            } catch (InvalidArgumentException) {
                $gaps++;
            }
        }

        return new ReportSourceBackfillResult(
            cursor: $batch->nextCursor,
            eligibleCount: $batch->eligibleCount,
            projectedCount: count($projected),
            gapCount: $gaps,
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
            || preg_match('/^act:(\d+)$/D', $context->sourceWatermark, $matches) !== 1
        ) {
            throw new InvalidArgumentException('accepted_production_backfill_context_invalid');
        }

        return (int) $matches[1];
    }
}
