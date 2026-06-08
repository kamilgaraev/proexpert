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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    public const OPERATION_BUDGET_LINES = 'budget_lines';
    public const OPERATION_BUDGET_AMOUNTS = 'budget_amounts';
    public const OPERATION_BUDGET_IMPORT = 'budget_import';
    public const OPERATION_BUDGET_VERSIONS = 'budget_versions';
    public const OPERATION_PERIOD_SETTINGS = 'period_settings';

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

    private const REOPENABLE_STATUSES = [
        self::STATUS_CLOSED,
        self::STATUS_SOFT_CLOSED,
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
            ['code' => self::OPERATION_BUDGET_LINES, 'label' => trans_message('budgeting.period_close.operations.budget_lines')],
            ['code' => self::OPERATION_BUDGET_AMOUNTS, 'label' => trans_message('budgeting.period_close.operations.budget_amounts')],
            ['code' => self::OPERATION_BUDGET_IMPORT, 'label' => trans_message('budgeting.period_close.operations.budget_import')],
            ['code' => self::OPERATION_BUDGET_VERSIONS, 'label' => trans_message('budgeting.period_close.operations.budget_versions')],
            ['code' => self::OPERATION_PERIOD_SETTINGS, 'label' => trans_message('budgeting.period_close.operations.period_settings')],
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

    public function canReopenStatus(?string $status): bool
    {
        return in_array((string) $status, self::REOPENABLE_STATUSES, true);
    }

    public function assertMutableStatus(?string $status): void
    {
        if ($this->isLockedStatus($status) || (string) $status === self::STATUS_REOPENED_FOR_ADJUSTMENT) {
            throw new DomainException(trans_message('budgeting.period_close.errors.period_locked'));
        }
    }

    public function assertPeriodMutable(BudgetPeriod $period, ?string $operation = null): void
    {
        $status = (string) $period->status;

        if ($status === self::STATUS_OPEN) {
            return;
        }

        if ($status === self::STATUS_REOPENED_FOR_ADJUSTMENT) {
            $this->assertActiveReopenAllows($period, $operation);
            return;
        }

        $this->assertMutableStatus($status);
    }

    public function assertVersionPeriodMutable(BudgetVersion $version, ?string $operation = null): void
    {
        $version->loadMissing('period');

        if (!$version->period instanceof BudgetPeriod) {
            throw new DomainException(trans_message('budgeting.periods.not_found'));
        }

        $this->assertPeriodMutable($version->period, $operation);
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(BudgetPeriod $period): array
    {
        $period->loadMissing('latestClosure.closedBy');
        $blockers = $this->blockers($period);
        $isLocked = $this->isLockedStatus((string) $period->status);
        $isReopened = (string) $period->status === self::STATUS_REOPENED_FOR_ADJUSTMENT;
        $latestClosure = $period->latestClosure;
        $activeOperations = $isReopened && $this->isActiveReopenClosure($latestClosure)
            ? $this->allowedOperationsForClosure($latestClosure)
            : [];
        $availableActions = [];

        if ($blockers === [] && $this->canCloseStatus((string) $period->status)) {
            $availableActions[] = 'close';
        }

        if ($this->canReopenStatus((string) $period->status)) {
            $availableActions[] = 'reopen';
        }

        foreach ($activeOperations as $operation) {
            $availableActions[] = match ($operation) {
                self::OPERATION_BUDGET_LINES => 'edit_budget_lines',
                self::OPERATION_BUDGET_AMOUNTS => 'edit_budget_amounts',
                self::OPERATION_BUDGET_IMPORT => 'import_budget',
                self::OPERATION_BUDGET_VERSIONS => 'replace_budget_version',
                self::OPERATION_PERIOD_SETTINGS => 'edit_period_settings',
                default => null,
            };
        }

        return [
            ...$this->periodClosureSummary($period),
            'workflow_status' => $this->workflowStatus((string) $period->status),
            'can_close' => $blockers === [] && $this->canCloseStatus((string) $period->status),
            'can_reopen' => $this->canReopenStatus((string) $period->status),
            'blockers' => array_map(
                static fn (BudgetPeriodCloseBlocker $blocker): array => $blocker->toArray(),
                $blockers
            ),
            'available_actions' => array_values(array_unique(array_filter($availableActions))),
            'locked_operations' => $isLocked ? $this->lockedOperations() : [],
            'active_reopen_operations' => $activeOperations,
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
        $isReopened = (string) $period->status === self::STATUS_REOPENED_FOR_ADJUSTMENT;
        $reopenActive = $isReopened && $this->isActiveReopenClosure($latestClosure);
        $metadata = is_array($latestClosure?->metadata) ? $latestClosure->metadata : [];
        $reopenedUntil = $latestClosure instanceof BudgetPeriodClosure
            ? $this->reopenedUntil($latestClosure)
            : null;

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
            'reopen_active' => $reopenActive,
            'reopen_expired' => $isReopened && !$reopenActive,
            'reopened_until' => $isReopened ? $reopenedUntil?->toIso8601String() : null,
            'reopen_reason' => $isReopened ? $latestClosure?->reason : null,
            'reopened_by' => $isReopened && $closedBy instanceof User ? [
                'id' => $closedBy->id,
                'name' => $closedBy->name,
                'email' => $closedBy->email,
            ] : null,
            'adjustment_mode' => is_string($metadata['adjustment_mode'] ?? null) ? $metadata['adjustment_mode'] : null,
            'change_scope' => is_string($metadata['change_scope'] ?? null) ? $metadata['change_scope'] : null,
            'change_objects' => is_array($metadata['change_objects'] ?? null) ? $metadata['change_objects'] : [],
            'allowed_operations' => $isReopened ? $this->allowedOperationsForClosure($latestClosure) : [],
            'plan_fact_actualized_at' => is_string($metadata['plan_fact_actualized_at'] ?? null)
                ? $metadata['plan_fact_actualized_at']
                : null,
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
                ->with('latestClosure')
                ->lockForUpdate()
                ->first();

            if (!$lockedPeriod instanceof BudgetPeriod) {
                throw new DomainException(trans_message('budgeting.periods.not_found'));
            }

            $previousStatus = (string) $lockedPeriod->status;
            $previousClosure = $lockedPeriod->latestClosure;
            $this->assertCanClose($lockedPeriod);
            $actualization = $this->managementActualizationSnapshot($lockedPeriod);

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
                    'previous_closure_uuid' => $previousClosure?->uuid,
                    'previous_closure_status' => $previousClosure?->closure_status,
                    'reclosed_after_reopen' => $previousStatus === self::STATUS_REOPENED_FOR_ADJUSTMENT,
                    'checked_at' => now()->toIso8601String(),
                    'blockers_count' => 0,
                    'plan_fact_actualized_at' => $actualization['actualized_at'],
                    'management_snapshot' => $actualization,
                    'source_of_truth' => $this->managementSourceOfTruth(),
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
            self::STATUS_REOPENED_FOR_ADJUSTMENT => 'reopened',
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

    private function assertActiveReopenAllows(BudgetPeriod $period, ?string $operation): void
    {
        $latestClosure = $period->relationLoaded('latestClosure')
            ? $period->latestClosure
            : $period->latestClosure()->first();

        if (!$this->isActiveReopenClosure($latestClosure)) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.active_window_required'));
        }

        if ($operation === null || trim($operation) === '') {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.operation_required'));
        }

        if (!$this->closureAllowsOperation($latestClosure, $operation)) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.operation_not_allowed'));
        }
    }

    public function isActiveReopenClosure(?BudgetPeriodClosure $closure): bool
    {
        if (!$closure instanceof BudgetPeriodClosure) {
            return false;
        }

        if ((string) $closure->closure_status !== self::STATUS_REOPENED_FOR_ADJUSTMENT) {
            return false;
        }

        $reopenedUntil = $this->reopenedUntil($closure);

        if (!$reopenedUntil instanceof CarbonInterface) {
            return false;
        }

        return $reopenedUntil->greaterThan(now());
    }

    public function closureAllowsOperation(?BudgetPeriodClosure $closure, string $operation): bool
    {
        return in_array($operation, $this->allowedOperationsForClosure($closure), true);
    }

    /**
     * @return list<string>
     */
    public function allowedOperationsForClosure(?BudgetPeriodClosure $closure): array
    {
        $metadata = is_array($closure?->metadata) ? $closure->metadata : [];
        $operations = $metadata['allowed_operations'] ?? [];

        if (!is_array($operations)) {
            return [];
        }

        $knownOperations = array_column($this->lockedOperations(), 'code');

        return array_values(array_intersect(
            array_values(array_unique(array_filter(
                $operations,
                static fn (mixed $operation): bool => is_string($operation) && trim($operation) !== ''
            ))),
            $knownOperations
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function managementActualizationSnapshot(BudgetPeriod $period): array
    {
        $versionIds = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $activeVersionIds = BudgetVersion::query()
            ->where('budget_period_id', $period->id)
            ->where('status', BudgetWorkflowService::STATUS_ACTIVE)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $planTotal = $activeVersionIds === [] ? 0.0 : (float) BudgetAmount::query()
            ->whereHas('line', fn (Builder $query): Builder => $query->whereIn('budget_version_id', $activeVersionIds))
            ->sum('plan_amount');

        $forecastTotal = $activeVersionIds === [] ? 0.0 : (float) BudgetAmount::query()
            ->whereHas('line', fn (Builder $query): Builder => $query->whereIn('budget_version_id', $activeVersionIds))
            ->sum('forecast_amount');

        return [
            'actualized_at' => now()->toIso8601String(),
            'active_versions_count' => count($activeVersionIds),
            'versions_count' => count($versionIds),
            'plan_total' => round($planTotal, 2),
            'forecast_total' => round($forecastTotal, 2),
            'currency' => 'RUB',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function managementSourceOfTruth(): array
    {
        return [
            'management_budgeting' => 'prohelper',
            'regulated_accounting' => '1c',
        ];
    }

    private function reopenedUntil(BudgetPeriodClosure $closure): ?CarbonInterface
    {
        $value = $closure->getAttributes()['reopened_until'] ?? null;

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
