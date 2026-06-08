<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetPeriodCloseBlocker;
use App\BusinessModules\Features\Budgeting\Exceptions\BudgetPeriodCloseBlockedException;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetImportBatch;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriodClosure;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BudgetPeriodClosureService
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING = 'closing';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SOFT_CLOSED = 'soft_closed';
    public const STATUS_REOPENED_FOR_ADJUSTMENT = 'reopened_for_adjustment';
    public const STATUS_ARCHIVED = 'archived';

    private const LOCKED_STATUSES = [
        self::STATUS_CLOSING,
        self::STATUS_CLOSED,
        self::STATUS_SOFT_CLOSED,
        self::STATUS_ARCHIVED,
    ];

    private const CLOSABLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_REOPENED_FOR_ADJUSTMENT,
    ];

    private const UNFINISHED_VERSION_STATUSES = [
        BudgetWorkflowService::STATUS_DRAFT,
        BudgetWorkflowService::STATUS_ON_APPROVAL,
        BudgetWorkflowService::STATUS_APPROVED,
    ];

    /**
     * @return list<array{code:string,label:string}>
     */
    public function lockedOperations(): array
    {
        return [
            ['code' => 'budget_lines', 'label' => trans_message('budgeting.period_close.operations.budget_lines')],
            ['code' => 'budget_amounts', 'label' => trans_message('budgeting.period_close.operations.budget_amounts')],
            ['code' => 'budget_import', 'label' => trans_message('budgeting.period_close.operations.budget_import')],
            ['code' => 'budget_versions', 'label' => trans_message('budgeting.period_close.operations.budget_versions')],
            ['code' => 'period_settings', 'label' => trans_message('budgeting.period_close.operations.period_settings')],
        ];
    }

    public function isLockedStatus(?string $status): bool
    {
        return in_array((string) $status, self::LOCKED_STATUSES, true);
    }

    public function canCloseStatus(?string $status): bool
    {
        return in_array((string) $status, self::CLOSABLE_STATUSES, true);
    }

    public function assertMutableStatus(?string $status): void
    {
        if ($this->isLockedStatus($status)) {
            throw new DomainException(trans_message('budgeting.period_close.errors.period_locked'));
        }
    }

    public function assertPeriodMutable(BudgetPeriod $period): void
    {
        $this->assertMutableStatus((string) $period->status);
    }

    public function assertVersionPeriodMutable(BudgetVersion $version): void
    {
        $version->loadMissing('period');

        if (!$version->period instanceof BudgetPeriod) {
            throw new DomainException(trans_message('budgeting.periods.not_found'));
        }

        $this->assertPeriodMutable($version->period);
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(BudgetPeriod $period): array
    {
        $period->loadMissing('latestClosure.closedBy');
        $blockers = $this->blockers($period);
        $isLocked = $this->isLockedStatus((string) $period->status);

        return [
            ...$this->periodClosureSummary($period),
            'workflow_status' => $this->workflowStatus((string) $period->status),
            'can_close' => $blockers === [] && $this->canCloseStatus((string) $period->status),
            'blockers' => array_map(
                static fn (BudgetPeriodCloseBlocker $blocker): array => $blocker->toArray(),
                $blockers
            ),
            'available_actions' => $blockers === [] && $this->canCloseStatus((string) $period->status) ? ['close'] : [],
            'locked_operations' => $isLocked ? $this->lockedOperations() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function periodClosureSummary(BudgetPeriod $period): array
    {
        $period->loadMissing('latestClosure.closedBy');
        $latestClosure = $period->latestClosure;
        $closedBy = $latestClosure?->closedBy;
        $isLocked = $this->isLockedStatus((string) $period->status);

        return [
            'period_id' => (string) $period->uuid,
            'status' => (string) $period->status,
            'status_label' => trans_message("budgeting.statuses.periods.{$period->status}"),
            'is_closed' => $isLocked,
            'closed_at' => $isLocked ? $latestClosure?->closed_at?->toIso8601String() : null,
            'closed_reason' => $isLocked ? $latestClosure?->reason : null,
            'closed_by' => $isLocked && $closedBy instanceof User ? [
                'id' => $closedBy->id,
                'name' => $closedBy->name,
                'email' => $closedBy->email,
            ] : null,
            'closure_status' => $latestClosure?->closure_status,
            'closure_mode' => $latestClosure?->closure_mode,
        ];
    }

    /**
     * @return list<BudgetPeriodCloseBlocker>
     */
    public function blockers(BudgetPeriod $period): array
    {
        $status = (string) $period->status;

        if ($this->isLockedStatus($status)) {
            return [$this->statusBlocker($status)];
        }

        if (!$this->canCloseStatus($status)) {
            return [$this->blocker('period_status_not_ready')];
        }

        $blockers = [];
        $versionsCount = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->count();

        if ($versionsCount === 0) {
            $blockers[] = $this->blocker('no_versions');
        }

        $activeVersionsCount = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->where('status', BudgetWorkflowService::STATUS_ACTIVE)
            ->count();

        if ($versionsCount > 0 && $activeVersionsCount === 0) {
            $blockers[] = $this->blocker('no_active_versions');
        }

        $unfinishedVersionCounts = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->whereIn('status', self::UNFINISHED_VERSION_STATUSES)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $unfinishedTotal = array_sum($unfinishedVersionCounts);
        if ($unfinishedTotal > 0) {
            $blockers[] = $this->blocker('unfinished_versions', $unfinishedTotal, [
                'statuses' => $unfinishedVersionCounts,
            ]);
        }

        $activeVersionsWithoutLines = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->where('status', BudgetWorkflowService::STATUS_ACTIVE)
            ->whereDoesntHave('lines')
            ->count();

        if ($activeVersionsWithoutLines > 0) {
            $blockers[] = $this->blocker('active_version_without_lines', $activeVersionsWithoutLines);
        }

        $pendingImports = BudgetImportBatch::query()
            ->where('status', 'previewed')
            ->whereHas('version', fn (Builder $query) => $query->where('budget_period_id', $period->id))
            ->count();

        if ($pendingImports > 0) {
            $blockers[] = $this->blocker('pending_imports', $pendingImports);
        }

        $startsAt = $period->starts_at?->startOfMonth()->toDateString() ?? '';
        $endsAt = $period->ends_at?->startOfMonth()->toDateString() ?? '';
        $amountsOutsidePeriod = BudgetAmount::query()
            ->whereHas('line.version', fn (Builder $query) => $query->where('budget_period_id', $period->id))
            ->where(function (Builder $query) use ($startsAt, $endsAt): void {
                $query
                    ->whereDate('month', '<', $startsAt)
                    ->orWhereDate('month', '>', $endsAt);
            })
            ->count();

        if ($amountsOutsidePeriod > 0) {
            $blockers[] = $this->blocker('amounts_outside_period', $amountsOutsidePeriod);
        }

        return $blockers;
    }

    public function assertCanClose(BudgetPeriod $period): void
    {
        $blockers = $this->blockers($period);

        if ($blockers !== []) {
            throw new BudgetPeriodCloseBlockedException($blockers);
        }
    }

    public function close(BudgetPeriod $period, User $user, string $reason, ?string $closureMode = null): BudgetPeriod
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(trans_message('budgeting.period_close.errors.reason_required'));
        }

        return DB::transaction(function () use ($period, $user, $reason, $closureMode): BudgetPeriod {
            $lockedPeriod = BudgetPeriod::query()
                ->whereKey($period->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPeriod instanceof BudgetPeriod) {
                throw new DomainException(trans_message('budgeting.periods.not_found'));
            }

            $previousStatus = (string) $lockedPeriod->status;
            $this->assertCanClose($lockedPeriod);

            $lockedPeriod->status = self::STATUS_CLOSING;
            $lockedPeriod->save();

            $lockedPeriod->status = self::STATUS_CLOSED;
            $lockedPeriod->save();

            BudgetPeriodClosure::create([
                'budget_period_id' => $lockedPeriod->id,
                'closure_status' => self::STATUS_CLOSED,
                'closure_mode' => $closureMode ?: 'management',
                'reason' => $reason,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'checked_at' => now()->toIso8601String(),
                    'blockers_count' => 0,
                ],
            ]);

            return $lockedPeriod->refresh()->load('latestClosure.closedBy');
        });
    }

    private function workflowStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_CLOSING => 'checking',
            self::STATUS_CLOSED, self::STATUS_SOFT_CLOSED, self::STATUS_ARCHIVED => 'closed',
            default => 'open',
        };
    }

    private function statusBlocker(string $status): BudgetPeriodCloseBlocker
    {
        return match ($status) {
            self::STATUS_CLOSING => $this->blocker('period_closing'),
            self::STATUS_ARCHIVED => $this->blocker('period_archived'),
            default => $this->blocker('period_already_closed'),
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function blocker(string $code, int $count = 1, array $meta = []): BudgetPeriodCloseBlocker
    {
        return new BudgetPeriodCloseBlocker(
            code: $code,
            message: trans_message("budgeting.period_close.blockers.{$code}"),
            count: $count,
            meta: $meta
        );
    }
}
