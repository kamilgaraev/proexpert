<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use App\Models\CompletedWork;
use App\Models\CompletedWorkHistoryTransformation;
use App\Models\PerformanceActLine;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class CompletedWorkReconciliationQuery
{
    private const ACTIVE_ACT_STATUSES = ['draft', 'pending_approval', 'approved', 'signed'];

    public const DEFAULT_BATCH_SIZE = 50;

    public const MAX_BATCH_SIZE = 100;

    public function __construct(private readonly CompletedWorkReconciliationService $classifier) {}

    public function collect(int $organizationId, int $projectId, ?int $afterId = null, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        if ($organizationId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('completed_work_reconciliation_scope_invalid');
        }

        $batchSize = min(max($batchSize, 1), self::MAX_BATCH_SIZE);
        $base = $this->scope($organizationId, $projectId);
        $total = (clone $base)->count();

        $works = (clone $base)
            ->when($afterId !== null, static fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($batchSize + 1)
            ->get();

        $hasMore = $works->count() > $batchSize;
        $page = $works->take($batchSize);
        $lineActs = PerformanceActLine::query()
            ->whereIn('completed_work_id', $page->pluck('id'))
            ->where('line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->whereHas('performanceAct', static function ($query) use ($projectId): void {
                $query->where(static function ($projectQuery) use ($projectId): void {
                    $projectQuery->where('project_id', $projectId)->orWhereNull('project_id');
                });
            })
            ->with(['performanceAct:id,project_id,act_document_number,period_start,period_end,status,is_approved,signed_file_id,signed_by_user_id,signed_at,annulled_at'])
            ->get()
            ->groupBy('completed_work_id');
        $acceptances = $page->isEmpty()
            ? collect()
            : AcceptanceScopeWorkQuantity::query()
                ->whereIn('completed_work_id', $page->pluck('id'))
                ->with(['scope:id,status,accepted_at,handed_over_at'])
                ->get()
                ->groupBy('completed_work_id');
        $transformations = $page->isEmpty()
            ? collect()
            : CompletedWorkHistoryTransformation::query()
                ->whereIn('completed_work_id', $page->pluck('id'))
                ->get()
                ->keyBy('completed_work_id');
        $journalIds = $page->pluck('journal_work_volume_id')->filter()->unique()->values();
        $duplicateJournalIds = $journalIds->isEmpty()
            ? collect()
            : (clone $base)->whereIn('journal_work_volume_id', $journalIds)
                ->whereNotNull('journal_work_volume_id')
                ->select('journal_work_volume_id')
                ->groupBy('journal_work_volume_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('journal_work_volume_id');

        $duplicateWorkIds = [];
        foreach ($duplicateJournalIds as $journalId) {
            $duplicateWorkIds[(int) $journalId] = (clone $base)
                ->where('journal_work_volume_id', $journalId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $source = $page->map(fn (CompletedWork $work): array => [
            'id' => (int) $work->id,
            'organization_id' => (int) $work->organization_id,
            'project_id' => (int) $work->project_id,
            'quantity' => $work->quantity,
            'completed_quantity' => $work->completed_quantity,
            'status' => $work->status,
            'journal_entry_id' => $work->journal_entry_id,
            'journal_work_volume_id' => $work->journal_work_volume_id,
            'total_amount' => $work->total_amount,
            'acts' => ($lineActs->get($work->id, collect())->pluck('performanceAct')->filter()->unique('id')->map(static fn ($act): array => [
                'id' => (int) $act->id,
                'act_document_number' => $act->act_document_number,
                'period_start' => $act->period_start?->toDateString(),
                'period_end' => $act->period_end?->toDateString(),
                'status' => $act->status,
                'is_approved' => (bool) $act->is_approved,
                'signed_file_id' => $act->signed_file_id,
                'signed_by_user_id' => $act->signed_by_user_id,
                'signed_at' => $act->signed_at?->toIso8601String(),
                'annulled_at' => $act->annulled_at?->toIso8601String(),
            ])->values()->all()),
        ])->all();

        $reports = $this->classifier->analyze($source, $organizationId, $projectId);
        $reportsById = [];
        foreach ($reports as $report) {
            $id = $report['work_id'];
            $journalId = (int) ($report['source']['journal_work_volume_id'] ?? 0);
            if (isset($duplicateWorkIds[$journalId]) && ! in_array('duplicate_journal_volume', $report['issues'], true)) {
                $report['issues'][] = 'duplicate_journal_volume';
                $report['duplicate_work_ids'] = $duplicateWorkIds[$journalId];
                $report['requires_manual_review'] = true;
                $report['resolved_quantity'] = null;
            }
            $acceptanceRows = $acceptances->get($id, collect());
            $report['acceptances'] = $acceptanceRows->map(static fn (AcceptanceScopeWorkQuantity $row): array => [
                'id' => (int) $row->id,
                'scope_id' => (int) $row->acceptance_scope_id,
                'status' => $row->scope?->status,
                'accepted_quantity' => $row->accepted_quantity,
                'accepted_at' => $row->scope?->accepted_at?->toIso8601String(),
                'handed_over_at' => $row->scope?->handed_over_at?->toIso8601String(),
            ])->values()->all();
            if ($acceptanceRows->contains(static fn (AcceptanceScopeWorkQuantity $row): bool => (float) $row->accepted_quantity > 0
                || $row->scope?->accepted_at !== null
                || $row->scope?->handed_over_at !== null)) {
                $report['protected_history'] = true;
            }
            $report['active_acts'] = array_values(array_filter($report['acts'], static fn (array $act): bool => $act['annulled_at'] === null && in_array($act['status'], self::ACTIVE_ACT_STATUSES, true)));
            $report['signed_history'] = array_values(array_filter($report['acts'], static fn (array $act): bool => $act['is_approved'] || in_array($act['status'], ['approved', 'signed'], true) || $act['signed_at'] !== null));
            $transformation = $transformations->get($id);
            $report['transformation'] = $transformation?->toReport();
            $plan = CompletedWorkHistoryTransformationRules::plan($report, $report['transformation']);
            $report['category'] = $plan['category'];
            $report['auto_action'] = $plan['auto_action'];
            $report['auto_rule'] = $plan['rule'];
            $reportsById[$id] = $report;
        }

        $records = array_values($reportsById);
        $lastId = $page->last()?->id;

        return [
            'records' => $records,
            'meta' => [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'total' => $total,
                'returned' => count($records),
                'manual_review' => count(array_filter($records, static fn (array $record): bool => $record['requires_manual_review'] || $record['auto_action'] === CompletedWorkHistoryTransformationRules::ACTION_SKIP_MANUAL)),
                'protected_history' => count(array_filter($records, static fn (array $record): bool => $record['protected_history'])),
                'already_transformed' => count(array_filter($records, static fn (array $record): bool => $record['transformation'] !== null)),
                'batch_size' => $batchSize,
                'next_cursor' => $hasMore && $lastId !== null ? (int) $lastId : null,
                'has_more' => $hasMore,
            ],
        ];
    }

    public function report(int $organizationId, int $projectId, int $workId): array
    {
        $page = $this->collect($organizationId, $projectId, $workId > 1 ? $workId - 1 : null, 1);
        $record = collect($page['records'])->firstWhere('work_id', $workId);
        if (! is_array($record)) {
            throw new InvalidArgumentException('completed_work_reconciliation_identity_invalid');
        }

        return $record;
    }

    private function scope(int $organizationId, int $projectId): Builder
    {
        return CompletedWork::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at');
    }
}
