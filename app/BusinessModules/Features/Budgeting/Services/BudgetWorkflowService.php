<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class BudgetWorkflowService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ON_APPROVAL = 'on_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(private readonly BudgetPeriodClosureService $periodClosureService)
    {
    }

    /**
     * @return list<string>
     */
    public function allowedActions(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => ['edit', 'import', 'submit', 'archive'],
            self::STATUS_ON_APPROVAL => ['approve', 'reject'],
            self::STATUS_APPROVED => ['activate', 'archive'],
            self::STATUS_ACTIVE, self::STATUS_REPLACED => ['archive'],
            default => [],
        };
    }

    public function assertCanEditLines(string $versionStatus, string $periodStatus): void
    {
        if ($versionStatus !== self::STATUS_DRAFT) {
            throw new DomainException(trans_message('budgeting.errors.version_not_editable'));
        }

        $this->periodClosureService->assertMutableStatus($periodStatus);
    }

    public function transition(string $currentStatus, string $action, bool $hasLines = true): string
    {
        if ($action === 'submit') {
            if ($currentStatus !== self::STATUS_DRAFT) {
                throw new DomainException(trans_message('budgeting.errors.workflow_submit_forbidden'));
            }

            if (!$hasLines) {
                throw new DomainException(trans_message('budgeting.errors.workflow_submit_empty'));
            }

            return self::STATUS_ON_APPROVAL;
        }

        if ($action === 'approve') {
            if ($currentStatus !== self::STATUS_ON_APPROVAL) {
                throw new DomainException(trans_message('budgeting.errors.workflow_approve_forbidden'));
            }

            return self::STATUS_APPROVED;
        }

        if ($action === 'reject') {
            if ($currentStatus !== self::STATUS_ON_APPROVAL) {
                throw new DomainException(trans_message('budgeting.errors.workflow_reject_forbidden'));
            }

            return self::STATUS_DRAFT;
        }

        if ($action === 'activate') {
            if ($currentStatus !== self::STATUS_APPROVED) {
                throw new DomainException(trans_message('budgeting.errors.workflow_activate_forbidden'));
            }

            return self::STATUS_ACTIVE;
        }

        if ($action === 'archive') {
            if (!in_array($currentStatus, [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_ACTIVE, self::STATUS_REPLACED], true)) {
                throw new DomainException(trans_message('budgeting.errors.workflow_archive_forbidden'));
            }

            return self::STATUS_ARCHIVED;
        }

        throw new DomainException(trans_message('budgeting.errors.workflow_action_unknown'));
    }

    public function transitionVersion(BudgetVersion $version, string $action, User $user, ?string $comment = null): BudgetVersion
    {
        return DB::transaction(function () use ($version, $action, $user, $comment): BudgetVersion {
            $version->loadMissing('period');
            if (in_array($action, ['submit', 'approve', 'reject', 'activate', 'archive'], true)) {
                $this->periodClosureService->assertVersionPeriodMutable(
                    $version,
                    BudgetPeriodClosureService::OPERATION_BUDGET_VERSIONS
                );
            }

            $from = (string) $version->status;
            $to = $this->transition($from, $action, $action !== 'submit' || $version->lines()->exists());

            if ($to === self::STATUS_ACTIVE) {
                BudgetVersion::query()
                    ->where('organization_id', $version->organization_id)
                    ->where('budget_period_id', $version->budget_period_id)
                    ->where('scenario_id', $version->scenario_id)
                    ->where('budget_kind', $version->budget_kind)
                    ->where('id', '!=', $version->id)
                    ->where('status', self::STATUS_ACTIVE)
                    ->update(['status' => self::STATUS_REPLACED]);
            }

            $history = $version->workflow_history ?? [];
            $history[] = [
                'action' => $action,
                'from' => $from,
                'to' => $to,
                'user_id' => $user->id,
                'comment' => $comment,
                'created_at' => now()->toIso8601String(),
            ];

            $version->status = $to;
            $version->workflow_history = $history;

            if ($action === 'submit') {
                $version->submitted_at = now();
                $version->submitted_by = $user->id;
            }

            if ($action === 'approve') {
                $version->approved_at = now();
                $version->approved_by = $user->id;
            }

            if ($action === 'activate') {
                $version->activated_at = now();
                $version->activated_by = $user->id;
            }

            $version->save();

            return $version->refresh();
        });
    }

}
