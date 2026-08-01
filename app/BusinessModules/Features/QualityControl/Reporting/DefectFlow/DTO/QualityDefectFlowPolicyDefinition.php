<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowCanonicalJson;

final readonly class QualityDefectFlowPolicyDefinition
{
    public function __construct(
        public string $policyCode,
        public int $version,
        private array $policy,
    ) {}

    public static function v1(): self
    {
        return new self('quality-defect-flow.v1', 1, [
            'active_statuses' => ['draft', 'open', 'assigned', 'in_progress', 'ready_for_review', 'rejected'],
            'ageing_clock' => [
                'basis' => 'calendar_days',
                'event_timezone' => 'UTC',
                'display_timezone_source' => 'reader',
                'interval' => 'left_closed_right_open',
                'terminal_handling' => 'stop_at_terminal_event',
                'sla_defined' => false,
            ],
            'assignment_attribution' => 'event_snapshot_assignee',
            'cohort_start' => 'created',
            'deterministic_order' => ['occurred_at_utc', 'event_id'],
            'due_date_semantics' => 'event_snapshot_value',
            'gap_codes' => [
                'invalid_enum',
                'lineage_breach',
                'missing_initial_event',
                'source_contract_missing',
                'time_inversion',
            ],
            'owner_attribution' => 'event_snapshot_contractor',
            'policy_code' => 'quality-defect-flow.v1',
            'reopen' => [
                'enabled' => false,
                'count' => 0,
                'requires_new_policy' => true,
            ],
            'return_definition' => [
                'event_kind' => 'returned_for_rework',
                'from_status' => 'ready_for_review',
                'to_status' => 'rejected',
            ],
            'severity_semantics' => 'event_snapshot_value',
            'terminal_reasons' => ['cancelled_by_user'],
            'terminal_statuses' => ['resolved', 'cancelled'],
            'version' => 1,
        ]);
    }

    public function canonicalPolicy(): array
    {
        return QualityDefectFlowCanonicalJson::sort($this->policy);
    }

    public function hash(): string
    {
        return QualityDefectFlowCanonicalJson::hash($this->canonicalPolicy());
    }

    public function allows(
        QualityDefectFlowEventKind $eventKind,
        ?QualityDefectStatusEnum $fromStatus,
        QualityDefectStatusEnum $toStatus,
        ?QualityDefectFlowTerminalReason $terminalReason,
    ): bool {
        if ($eventKind === QualityDefectFlowEventKind::CANCELLED) {
            return $terminalReason === QualityDefectFlowTerminalReason::CANCELLED_BY_USER
                && $toStatus === QualityDefectStatusEnum::CANCELLED
                && in_array($fromStatus, [
                    QualityDefectStatusEnum::DRAFT,
                    QualityDefectStatusEnum::OPEN,
                    QualityDefectStatusEnum::ASSIGNED,
                    QualityDefectStatusEnum::IN_PROGRESS,
                    QualityDefectStatusEnum::REJECTED,
                ], true);
        }

        if ($terminalReason !== null) {
            return false;
        }

        return match ($eventKind) {
            QualityDefectFlowEventKind::CREATED => $fromStatus === null
                && in_array($toStatus, [QualityDefectStatusEnum::OPEN, QualityDefectStatusEnum::ASSIGNED], true),
            QualityDefectFlowEventKind::ASSIGNED => $toStatus === QualityDefectStatusEnum::ASSIGNED
                && in_array($fromStatus, [QualityDefectStatusEnum::OPEN, QualityDefectStatusEnum::REJECTED], true),
            QualityDefectFlowEventKind::STARTED => $toStatus === QualityDefectStatusEnum::IN_PROGRESS
                && in_array($fromStatus, [
                    QualityDefectStatusEnum::OPEN,
                    QualityDefectStatusEnum::ASSIGNED,
                    QualityDefectStatusEnum::REJECTED,
                ], true),
            QualityDefectFlowEventKind::SUBMITTED_FOR_REVIEW => $toStatus === QualityDefectStatusEnum::READY_FOR_REVIEW
                && in_array($fromStatus, [
                    QualityDefectStatusEnum::OPEN,
                    QualityDefectStatusEnum::ASSIGNED,
                    QualityDefectStatusEnum::IN_PROGRESS,
                    QualityDefectStatusEnum::REJECTED,
                ], true),
            QualityDefectFlowEventKind::VERIFIED_RESOLVED => $fromStatus === QualityDefectStatusEnum::READY_FOR_REVIEW
                && $toStatus === QualityDefectStatusEnum::RESOLVED,
            QualityDefectFlowEventKind::RETURNED_FOR_REWORK => $fromStatus === QualityDefectStatusEnum::READY_FOR_REVIEW
                && $toStatus === QualityDefectStatusEnum::REJECTED,
            QualityDefectFlowEventKind::REJECTED => $toStatus === QualityDefectStatusEnum::REJECTED
                && in_array($fromStatus, [
                    QualityDefectStatusEnum::DRAFT,
                    QualityDefectStatusEnum::OPEN,
                    QualityDefectStatusEnum::ASSIGNED,
                    QualityDefectStatusEnum::IN_PROGRESS,
                    QualityDefectStatusEnum::READY_FOR_REVIEW,
                    QualityDefectStatusEnum::REJECTED,
                ], true),
            QualityDefectFlowEventKind::CANCELLED => false,
        };
    }
}
