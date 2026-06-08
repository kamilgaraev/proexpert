<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriodClosure;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class BudgetPeriodReopenService
{
    private const MODE_OPERATIONS = [
        'budget_lines' => [
            BudgetPeriodClosureService::OPERATION_BUDGET_LINES,
            BudgetPeriodClosureService::OPERATION_BUDGET_AMOUNTS,
        ],
        'budget_import' => [
            BudgetPeriodClosureService::OPERATION_BUDGET_IMPORT,
            BudgetPeriodClosureService::OPERATION_BUDGET_LINES,
            BudgetPeriodClosureService::OPERATION_BUDGET_AMOUNTS,
        ],
        'version_replacement' => [
            BudgetPeriodClosureService::OPERATION_BUDGET_VERSIONS,
        ],
        'period_settings' => [
            BudgetPeriodClosureService::OPERATION_PERIOD_SETTINGS,
        ],
        'mixed' => [
            BudgetPeriodClosureService::OPERATION_BUDGET_LINES,
            BudgetPeriodClosureService::OPERATION_BUDGET_AMOUNTS,
            BudgetPeriodClosureService::OPERATION_BUDGET_IMPORT,
            BudgetPeriodClosureService::OPERATION_BUDGET_VERSIONS,
            BudgetPeriodClosureService::OPERATION_PERIOD_SETTINGS,
        ],
    ];

    public function __construct(private readonly BudgetPeriodClosureService $closureService)
    {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reopen(BudgetPeriod $period, User $user, array $input): BudgetPeriod
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.reason_required'));
        }

        $expiresAt = $this->expiresAt($input['expires_at'] ?? null);
        $adjustmentMode = trim((string) ($input['adjustment_mode'] ?? ''));
        $allowedOperations = $this->allowedOperations($adjustmentMode, $input['allowed_operations'] ?? null);
        $changeScope = trim((string) ($input['change_scope'] ?? ''));
        $changeObjects = $this->changeObjects($input['change_objects'] ?? []);

        if ($changeScope === '' && $changeObjects === []) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.change_scope_required'));
        }

        return DB::transaction(function () use (
            $period,
            $user,
            $reason,
            $expiresAt,
            $adjustmentMode,
            $allowedOperations,
            $changeScope,
            $changeObjects
        ): BudgetPeriod {
            $lockedPeriod = BudgetPeriod::query()
                ->whereKey($period->id)
                ->with('latestClosure')
                ->lockForUpdate()
                ->first();

            if (!$lockedPeriod instanceof BudgetPeriod) {
                throw new DomainException(trans_message('budgeting.periods.not_found'));
            }

            $previousStatus = (string) $lockedPeriod->status;
            if (!$this->closureService->canReopenStatus($previousStatus)) {
                throw new DomainException(trans_message('budgeting.period_reopen.errors.status_not_reopenable'));
            }

            $previousClosure = $lockedPeriod->latestClosure;
            $actualization = $this->closureService->managementActualizationSnapshot($lockedPeriod);

            $lockedPeriod->status = BudgetPeriodClosureService::STATUS_REOPENED_FOR_ADJUSTMENT;
            $lockedPeriod->save();

            BudgetPeriodClosure::create([
                'budget_period_id' => $lockedPeriod->id,
                'closure_status' => BudgetPeriodClosureService::STATUS_REOPENED_FOR_ADJUSTMENT,
                'closure_mode' => 'managed_adjustment',
                'reason' => $reason,
                'closed_by' => $user->id,
                'reopened_until' => $expiresAt,
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'previous_closure_uuid' => $previousClosure?->uuid,
                    'previous_closure_status' => $previousClosure?->closure_status,
                    'adjustment_mode' => $adjustmentMode,
                    'allowed_operations' => $allowedOperations,
                    'change_scope' => $changeScope,
                    'change_objects' => $changeObjects,
                    'plan_fact_actualized_at' => $actualization['actualized_at'],
                    'management_snapshot_before_reopen' => $actualization,
                    'source_of_truth' => $this->closureService->managementSourceOfTruth(),
                ],
            ]);

            return $lockedPeriod->refresh()->load('latestClosure.closedBy');
        }, 3);
    }

    private function expiresAt(mixed $value): CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.expires_at_required'));
        }

        $expiresAt = CarbonImmutable::parse((string) $value);
        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.expires_at_future'));
        }

        return $expiresAt;
    }

    /**
     * @return list<string>
     */
    private function allowedOperations(string $adjustmentMode, mixed $requestedOperations): array
    {
        $modeOperations = self::MODE_OPERATIONS[$adjustmentMode] ?? [];
        if ($modeOperations === []) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.adjustment_mode_invalid'));
        }

        if (!is_array($requestedOperations) || $requestedOperations === []) {
            return $modeOperations;
        }

        $requested = array_values(array_unique(array_filter(
            $requestedOperations,
            static fn (mixed $operation): bool => is_string($operation) && trim($operation) !== ''
        )));
        $allowed = array_values(array_intersect($requested, $modeOperations));

        if ($allowed === []) {
            throw new DomainException(trans_message('budgeting.period_reopen.errors.operation_not_allowed'));
        }

        return $allowed;
    }

    /**
     * @return list<array{type:string,id:?string,description:?string}>
     */
    private function changeObjects(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $objects = [];
        foreach ($value as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = trim((string) ($object['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $id = isset($object['id']) && trim((string) $object['id']) !== ''
                ? trim((string) $object['id'])
                : null;
            $description = isset($object['description']) && trim((string) $object['description']) !== ''
                ? trim((string) $object['description'])
                : null;

            $objects[] = [
                'type' => mb_substr($type, 0, 64),
                'id' => $id !== null ? mb_substr($id, 0, 128) : null,
                'description' => $description !== null ? mb_substr($description, 0, 500) : null,
            ];
        }

        return $objects;
    }
}
