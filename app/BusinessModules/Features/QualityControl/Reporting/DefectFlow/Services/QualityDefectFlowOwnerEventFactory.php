<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowPolicyDefinition;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use DateTimeImmutable;
use InvalidArgumentException;

final class QualityDefectFlowOwnerEventFactory
{
    public function make(
        QualityDefect $defect,
        QualityDefectStatusHistory $history,
        QualityDefectFlowEventKind $eventKind,
        ?QualityDefectFlowTerminalReason $terminalReason = null,
    ): QualityDefectFlowEvent {
        $defectId = (int) $defect->getAttribute('id');
        $historyDefectId = (int) $history->getAttribute('quality_defect_id');
        $organizationId = (int) $defect->getAttribute('organization_id');
        if ($defectId <= 0
            || $historyDefectId !== $defectId
            || (int) $history->getAttribute('organization_id') !== $organizationId) {
            throw new InvalidArgumentException('quality_defect_flow_owner_lineage_invalid');
        }

        $from = $this->status($history->getRawOriginal('from_status'), true);
        $to = $this->status($history->getRawOriginal('to_status'), false);
        if ($to->value !== (string) $defect->getRawOriginal('status')) {
            throw new InvalidArgumentException('quality_defect_flow_owner_status_invalid');
        }

        $dueDate = $defect->getRawOriginal('due_date');
        if ($dueDate !== null) {
            $dueDate = substr((string) $dueDate, 0, 10);
        }

        return new QualityDefectFlowEvent(
            eventKind: $eventKind,
            fromStatus: $from,
            toStatus: $to,
            actorId: $this->nullablePositiveInt($history->getAttribute('changed_by')),
            occurredAt: new DateTimeImmutable((string) $history->getRawOriginal('changed_at')),
            snapshot: QualityDefectFlowSnapshot::fromArray([
                'schema_version' => QualityDefectFlowSnapshot::SCHEMA_VERSION,
                'organization_id' => (string) $organizationId,
                'project_id' => (string) ((int) $defect->getAttribute('project_id')),
                'quality_defect_id' => (string) $defectId,
                'contractor_id' => $this->nullableDecimalString($defect->getAttribute('contractor_id')),
                'schedule_task_id' => $this->nullableDecimalString($defect->getAttribute('schedule_task_id')),
                'severity' => (string) $defect->getRawOriginal('severity'),
                'due_date' => $dueDate,
                'has_due_date' => $dueDate !== null,
                'inspection_required' => (bool) $defect->getAttribute('inspection_required'),
                'assignee_id' => $this->nullableDecimalString($defect->getAttribute('assigned_to')),
                'source_link' => $this->sourceLink($defect),
            ]),
            sourceIdentity: [
                'kind' => 'quality_defect_status_history',
                'id' => (string) ((int) $history->getAttribute('id')),
            ],
            policy: QualityDefectFlowPolicyDefinition::v1(),
            terminalReason: $terminalReason,
        );
    }

    private function status(mixed $value, bool $nullable): ?QualityDefectStatusEnum
    {
        if ($value === null && $nullable) {
            return null;
        }
        if ($value instanceof QualityDefectStatusEnum) {
            return $value;
        }

        $status = QualityDefectStatusEnum::tryFrom((string) $value);
        if (! $status instanceof QualityDefectStatusEnum) {
            throw new InvalidArgumentException('quality_defect_flow_owner_status_invalid');
        }

        return $status;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = (int) $value;
        if ($id <= 0) {
            throw new InvalidArgumentException('quality_defect_flow_owner_actor_invalid');
        }

        return $id;
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        if ($id <= 0) {
            throw new InvalidArgumentException('quality_defect_flow_owner_dimension_invalid');
        }

        return (string) $id;
    }

    private function sourceLink(QualityDefect $defect): array
    {
        $metadata = $defect->getAttribute('metadata');
        $source = is_array($metadata) ? ($metadata['source'] ?? null) : null;
        if (! is_array($source)) {
            return ['classification' => 'quality_defect'];
        }

        if (($source['type'] ?? null) === 'work_constraint') {
            return ['classification' => 'work_constraint'];
        }

        if (($source['type'] ?? null) !== 'acceptance_finding') {
            return ['classification' => 'quality_defect'];
        }

        return [
            'classification' => 'acceptance_finding',
            'acceptance_scope_id' => $this->requiredDecimalString($source['acceptance_scope_id'] ?? null),
            'acceptance_session_id' => $this->requiredDecimalString($source['acceptance_session_id'] ?? null),
        ];
    }

    private function requiredDecimalString(mixed $value): string
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new InvalidArgumentException('quality_defect_flow_acceptance_lineage_invalid');
        }

        return (string) $id;
    }
}
