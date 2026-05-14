<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\DTOs\Workflow\WorkflowSurfaceData;

final class QualityDefectWorkflowService
{
    public function forDefect(QualityDefect $defect): WorkflowSurfaceData
    {
        $problemFlags = $this->problemFlags($defect);
        $availableActions = $this->availableActions($defect);

        return new WorkflowSurfaceData(
            stage: $this->stage($defect->status),
            stageLabel: trans_message('quality_control.workflow.stages.' . $this->stage($defect->status)),
            status: $defect->status->value,
            statusLabel: $defect->status->label(),
            nextAction: $availableActions[0] ?? null,
            nextActionLabel: isset($availableActions[0])
                ? trans_message('quality_control.workflow.actions.' . $availableActions[0])
                : null,
            availableActions: $availableActions,
            problemFlags: $problemFlags,
            blockers: array_values(array_map(
                static fn (array $flag): string => (string) $flag['message'],
                array_filter($problemFlags, static fn (array $flag): bool => $flag['severity'] === 'blocker')
            )),
            warnings: array_values(array_map(
                static fn (array $flag): string => (string) $flag['message'],
                array_filter($problemFlags, static fn (array $flag): bool => $flag['severity'] === 'warning')
            )),
            meta: [
                'overdue' => $defect->due_date !== null && $defect->due_date->isPast() && !in_array($defect->status, [
                    QualityDefectStatusEnum::RESOLVED,
                    QualityDefectStatusEnum::CANCELLED,
                ], true),
            ],
        );
    }

    public function availableActions(QualityDefect $defect): array
    {
        return match ($defect->status) {
            QualityDefectStatusEnum::DRAFT => ['open', 'cancel'],
            QualityDefectStatusEnum::OPEN => ['assign', 'start', 'resolve', 'cancel'],
            QualityDefectStatusEnum::ASSIGNED => ['start', 'resolve', 'cancel'],
            QualityDefectStatusEnum::IN_PROGRESS => ['resolve', 'cancel'],
            QualityDefectStatusEnum::READY_FOR_REVIEW => ['verify', 'reject'],
            QualityDefectStatusEnum::REJECTED => ['start', 'resolve', 'cancel'],
            QualityDefectStatusEnum::RESOLVED,
            QualityDefectStatusEnum::CANCELLED => [],
        };
    }

    public function problemFlags(QualityDefect $defect): array
    {
        $flags = [];

        if ($defect->schedule_task_id !== null && !in_array($defect->status, [
            QualityDefectStatusEnum::RESOLVED,
            QualityDefectStatusEnum::CANCELLED,
        ], true)) {
            $flags[] = [
                'code' => 'schedule_task_blocked_by_defect',
                'severity' => 'blocker',
                'message' => trans_message('quality_control.problem_flags.schedule_task_blocked_by_defect'),
                'target' => 'schedule_task',
                'target_id' => $defect->schedule_task_id,
            ];
        }

        if ($defect->due_date !== null && $defect->due_date->isPast() && !in_array($defect->status, [
            QualityDefectStatusEnum::RESOLVED,
            QualityDefectStatusEnum::CANCELLED,
        ], true)) {
            $flags[] = [
                'code' => 'quality_defect_overdue',
                'severity' => 'warning',
                'message' => trans_message('quality_control.problem_flags.quality_defect_overdue'),
                'target' => 'quality_defect',
                'target_id' => $defect->id,
            ];
        }

        if ($defect->inspection_required && $defect->status === QualityDefectStatusEnum::READY_FOR_REVIEW) {
            $flags[] = [
                'code' => 'verification_required',
                'severity' => 'warning',
                'message' => trans_message('quality_control.problem_flags.verification_required'),
                'target' => 'quality_defect',
                'target_id' => $defect->id,
            ];
        }

        return $flags;
    }

    private function stage(QualityDefectStatusEnum $status): string
    {
        return match ($status) {
            QualityDefectStatusEnum::DRAFT,
            QualityDefectStatusEnum::OPEN => 'registration',
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectStatusEnum::REJECTED => 'correction',
            QualityDefectStatusEnum::READY_FOR_REVIEW => 'verification',
            QualityDefectStatusEnum::RESOLVED => 'closed',
            QualityDefectStatusEnum::CANCELLED => 'cancelled',
        };
    }
}
