<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use BackedEnum;

final class DesignPackageWorkflow
{
    public const SUBMIT_FOR_REVIEW = 'submit_for_review';
    public const RETURN_TO_WORK = 'return_to_work';
    public const APPROVE = 'approve';
    public const ISSUE = 'issue';

    private const MODEL_CHANGE_STATUSES = [
        DesignPackageStatusEnum::DRAFT->value,
        DesignPackageStatusEnum::IN_WORK->value,
    ];

    private const BLOCKING_FLAGS = [
        'model_missing',
        'viewer_not_prepared',
        'viewer_preparation_failed',
    ];

    public static function actions(): array
    {
        return [
            self::SUBMIT_FOR_REVIEW,
            self::RETURN_TO_WORK,
            self::APPROVE,
            self::ISSUE,
        ];
    }

    public static function availableActions(DesignPackage $package): array
    {
        $status = self::statusValue($package->status);

        if (in_array($status, self::MODEL_CHANGE_STATUSES, true)) {
            $currentVersion = self::currentVersion($package);
            $flags = self::problemFlags($package, $currentVersion, self::preferredDerivative($currentVersion));

            return array_intersect($flags, self::BLOCKING_FLAGS) === []
                ? [self::SUBMIT_FOR_REVIEW]
                : [];
        }

        return match ($status) {
            DesignPackageStatusEnum::UNDER_REVIEW->value => [self::RETURN_TO_WORK, self::APPROVE],
            DesignPackageStatusEnum::APPROVED->value => [self::ISSUE],
            default => [],
        };
    }

    public static function canChangeModels(DesignPackage $package): bool
    {
        return in_array(self::statusValue($package->status), self::MODEL_CHANGE_STATUSES, true);
    }

    public static function requiresComment(string $action): bool
    {
        return $action === self::RETURN_TO_WORK;
    }

    public static function permissionForAction(string $action): ?string
    {
        return match ($action) {
            self::SUBMIT_FOR_REVIEW => 'design-management.edit',
            self::RETURN_TO_WORK => 'design-management.review',
            self::APPROVE,
            self::ISSUE => 'design-management.approve',
            default => null,
        };
    }

    public static function nextStatus(DesignPackage $package, string $action): ?DesignPackageStatusEnum
    {
        if (!in_array($action, self::availableActions($package), true)) {
            return null;
        }

        return self::targetStatus($action);
    }

    public static function targetStatus(string $action): ?DesignPackageStatusEnum
    {
        return match ($action) {
            self::SUBMIT_FOR_REVIEW => DesignPackageStatusEnum::UNDER_REVIEW,
            self::RETURN_TO_WORK => DesignPackageStatusEnum::IN_WORK,
            self::APPROVE => DesignPackageStatusEnum::APPROVED,
            self::ISSUE => DesignPackageStatusEnum::ISSUED,
            default => null,
        };
    }

    public static function isCompletedAction(DesignPackage $package, string $action): bool
    {
        $targetStatus = self::targetStatus($action);

        return $targetStatus instanceof DesignPackageStatusEnum
            && self::statusValue($package->status) === $targetStatus->value;
    }

    public static function currentVersion(DesignPackage $package): ?DesignArtifactVersion
    {
        if (!$package->relationLoaded('artifacts')) {
            return null;
        }

        foreach ($package->artifacts as $artifact) {
            if ($artifact->relationLoaded('currentVersion') && $artifact->currentVersion instanceof DesignArtifactVersion) {
                return $artifact->currentVersion;
            }
        }

        foreach ($package->artifacts as $artifact) {
            if ($artifact->relationLoaded('versions')) {
                $version = $artifact->versions->first();

                if ($version instanceof DesignArtifactVersion) {
                    return $version;
                }
            }
        }

        return null;
    }

    public static function preferredDerivative(?DesignArtifactVersion $version): ?DesignModelDerivative
    {
        if (!$version instanceof DesignArtifactVersion) {
            return null;
        }

        if ($version->relationLoaded('readyDerivative') && $version->readyDerivative instanceof DesignModelDerivative) {
            return $version->readyDerivative;
        }

        if ($version->relationLoaded('derivatives')) {
            return $version->derivatives
                ->first(static fn (DesignModelDerivative $item): bool => $item->viewer_provider === 'thatopen'
                    && $item->derivative_format === 'thatopen_frag');
        }

        return null;
    }

    public static function problemFlags(
        DesignPackage $package,
        ?DesignArtifactVersion $currentVersion,
        ?DesignModelDerivative $derivative
    ): array {
        $flags = [];
        $status = self::statusValue($package->status);

        if (!$currentVersion instanceof DesignArtifactVersion) {
            $flags[] = 'model_missing';
        }

        if (
            $currentVersion instanceof DesignArtifactVersion
            && (!$derivative instanceof DesignModelDerivative || DesignViewerConverter::isStale($derivative))
        ) {
            $flags[] = 'viewer_not_prepared';
        }

        if (
            $derivative instanceof DesignModelDerivative
            && self::statusValue($derivative->status) === DesignDerivativeStatusEnum::FAILED->value
        ) {
            $flags[] = 'viewer_preparation_failed';
        }

        if (
            $package->planned_issue_date !== null
            && $package->planned_issue_date->isPast()
            && !in_array($status, [
                DesignPackageStatusEnum::APPROVED->value,
                DesignPackageStatusEnum::ISSUED->value,
                DesignPackageStatusEnum::ARCHIVED->value,
            ], true)
        ) {
            $flags[] = 'planned_issue_overdue';
        }

        return $flags;
    }

    public static function workflowSummary(
        DesignPackage $package,
        ?DesignArtifactVersion $currentVersion,
        ?DesignModelDerivative $derivative,
        array $problemFlags,
        int $artifactsCount
    ): array {
        $status = self::statusValue($package->status);
        $availableActions = self::availableActions($package);
        $nextAction = $availableActions[0] ?? null;
        $derivativeStatus = self::derivativeStatus($derivative);

        return [
            'artifacts_count' => $artifactsCount,
            'models_count' => $artifactsCount,
            'current_version_id' => $currentVersion?->id,
            'derivative_status' => $derivativeStatus,
            'has_ready_viewer' => $derivative instanceof DesignModelDerivative
                && $derivativeStatus === DesignDerivativeStatusEnum::READY->value,
            'is_overdue' => in_array('planned_issue_overdue', $problemFlags, true),
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.packages.{$status}"),
            'next_action' => $nextAction,
            'next_action_label' => $nextAction !== null
                ? trans_message("design_management.actions.{$nextAction}")
                : null,
            'available_actions' => $availableActions,
            'available_action_details' => self::actionDetails($availableActions),
            'problem_flags' => self::problemFlagDetails($problemFlags),
        ];
    }

    public static function actionDetails(array $actions): array
    {
        return array_values(array_map(static fn (string $action): array => [
            'action' => $action,
            'label' => self::actionLabel($action),
            'requires_comment' => self::requiresComment($action),
            'permission' => self::permissionForAction($action),
        ], $actions));
    }

    public static function problemFlagDetails(array $problemFlags): array
    {
        return array_values(array_map(static fn (string $flag): array => [
            'code' => $flag,
            'label' => trans_message("design_management.problem_flags.{$flag}"),
            'severity' => in_array($flag, self::BLOCKING_FLAGS, true) ? 'blocking' : 'warning',
        ], array_values(array_unique($problemFlags))));
    }

    public static function workflowHistory(DesignPackage $package): array
    {
        $metadata = $package->metadata ?? [];
        $history = is_array($metadata['workflow_history'] ?? null)
            ? $metadata['workflow_history']
            : [];

        return array_values(array_map(static function (array $entry): array {
            $action = (string) ($entry['action'] ?? '');
            $fromStatus = (string) ($entry['from_status'] ?? '');
            $toStatus = (string) ($entry['to_status'] ?? '');

            return [
                'action' => $action,
                'action_label' => self::actionLabel($action),
                'from_status' => $fromStatus,
                'from_status_label' => self::statusLabel($fromStatus),
                'to_status' => $toStatus,
                'to_status_label' => self::statusLabel($toStatus),
                'user_id' => isset($entry['user_id']) ? (int) $entry['user_id'] : null,
                'comment' => $entry['comment'] ?? null,
                'at' => $entry['at'] ?? null,
            ];
        }, $history));
    }

    private static function derivativeStatus(?DesignModelDerivative $derivative): string
    {
        if (!$derivative instanceof DesignModelDerivative || DesignViewerConverter::isStale($derivative)) {
            return DesignDerivativeStatusEnum::MISSING->value;
        }

        return self::statusValue($derivative->status);
    }

    private static function actionLabel(string $action): string
    {
        return in_array($action, self::actions(), true)
            ? trans_message("design_management.actions.{$action}")
            : $action;
    }

    private static function statusLabel(string $status): string
    {
        $known = array_map(
            static fn (DesignPackageStatusEnum $item): string => $item->value,
            DesignPackageStatusEnum::cases()
        );

        return in_array($status, $known, true)
            ? trans_message("design_management.statuses.packages.{$status}")
            : $status;
    }

    private static function statusValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignPackageStatusEnum::DRAFT->value);
    }
}
