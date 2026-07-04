<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use DateTimeInterface;

final class SafetyRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'safety';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->incidents($organizationId, $projectId) as $incident) {
            yield $this->incidentChunk($incident);
        }

        foreach ($this->violations($organizationId, $projectId) as $violation) {
            yield $this->violationChunk($violation);
        }

        foreach ($this->permits($organizationId, $projectId) as $permit) {
            yield $this->permitChunk($permit);
        }

        foreach ($this->briefings($organizationId, $projectId) as $briefing) {
            yield $this->briefingChunk($briefing);
        }

        foreach ($this->correctiveActions($organizationId, $projectId) as $action) {
            yield $this->correctiveActionChunk($action);
        }

        foreach ($this->inspections($organizationId, $projectId) as $inspection) {
            yield $this->inspectionChunk($inspection);
        }

        foreach ($this->inspectionFindings($organizationId, $projectId) as $finding) {
            yield $this->inspectionFindingChunk($finding);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'safety_incident' => $this->singleIncident($organizationId, $entityId),
            'safety_violation' => $this->singleViolation($organizationId, $entityId),
            'safety_work_permit' => $this->singlePermit($organizationId, $entityId),
            'safety_briefing' => $this->singleBriefing($organizationId, $entityId),
            'safety_corrective_action' => $this->singleCorrectiveAction($organizationId, $entityId),
            'safety_inspection' => $this->singleInspection($organizationId, $entityId),
            'safety_inspection_finding' => $this->singleInspectionFinding($organizationId, $entityId),
            default => [],
        };
    }

    private function incidentChunk(SafetyIncident $incident): RagChunkData
    {
        $content = $this->lines([
            'Инцидент безопасности: '.$this->stringValue($incident->incident_number),
            'Проект: '.$this->stringValue($incident->project?->name),
            'Название: '.$this->stringValue($incident->title),
            'Тип: '.$this->stringValue($incident->incident_type),
            'Серьезность: '.$this->stringValue($incident->severity),
            'Статус: '.$this->stringValue($incident->status),
            'Дата: '.$this->dateTimeValue($incident->occurred_at),
            'Локация: '.$this->stringValue($incident->location_name),
            'Ответственный: '.$this->stringValue($incident->assignedUser?->name),
            'Описание: '.$this->stringValue($incident->description),
            'Немедленные меры: '.$this->stringValue($incident->immediate_actions),
            'Причина: '.$this->stringValue($incident->root_cause),
            'Корректирующие действия: '.$this->stringValue($incident->corrective_actions),
        ]);

        return $this->chunk(
            $incident->organization_id,
            $incident->project_id,
            'safety_incident',
            $incident->id,
            'Безопасность: '.$this->stringValue($incident->incident_number),
            $content,
            [
                'status' => $this->scalarValue($incident->status),
                'severity' => $this->scalarValue($incident->severity),
                'project_id' => $incident->project_id,
                'occurred_at' => $this->dateTimeValue($incident->occurred_at),
            ],
            $incident->updated_at
        );
    }

    private function violationChunk(SafetyViolation $violation): RagChunkData
    {
        $content = $this->lines([
            'Нарушение безопасности: '.$this->stringValue($violation->violation_number),
            'Проект: '.$this->stringValue($violation->project?->name),
            'Название: '.$this->stringValue($violation->title),
            'Серьезность: '.$this->stringValue($violation->severity),
            'Статус: '.$this->stringValue($violation->status),
            'Срок устранения: '.$this->dateValue($violation->due_date),
            'Локация: '.$this->stringValue($violation->location_name),
            'Ответственный: '.$this->stringValue($violation->assignedUser?->name),
            'Описание: '.$this->stringValue($violation->description),
            'Корректирующее действие: '.$this->stringValue($violation->corrective_action),
            'Решение: '.$this->stringValue($violation->resolution_comment),
        ]);

        return $this->chunk(
            $violation->organization_id,
            $violation->project_id,
            'safety_violation',
            $violation->id,
            'Нарушение: '.$this->stringValue($violation->violation_number),
            $content,
            [
                'status' => $this->scalarValue($violation->status),
                'severity' => $this->scalarValue($violation->severity),
                'project_id' => $violation->project_id,
                'due_date' => $this->dateValue($violation->due_date),
            ],
            $violation->updated_at
        );
    }

    private function permitChunk(SafetyWorkPermit $permit): RagChunkData
    {
        $content = $this->lines([
            'Наряд-допуск: '.$this->stringValue($permit->permit_number),
            'Проект: '.$this->stringValue($permit->project?->name),
            'Название: '.$this->stringValue($permit->title),
            'Тип работ: '.$this->stringValue($permit->permit_type),
            'Уровень риска: '.$this->stringValue($permit->risk_level),
            'Статус: '.$this->stringValue($permit->status),
            'Действует с: '.$this->dateTimeValue($permit->valid_from),
            'Действует до: '.$this->dateTimeValue($permit->valid_until),
            'Локация: '.$this->stringValue($permit->location_name),
            'Ответственный: '.$this->stringValue($permit->responsibleUser?->name),
            'Контроли: '.$this->arrayValue($permit->required_controls),
            'Комментарий согласования: '.$this->stringValue($permit->approval_comment),
            'Причина отклонения: '.$this->stringValue($permit->rejection_reason),
        ]);

        return $this->chunk(
            $permit->organization_id,
            $permit->project_id,
            'safety_work_permit',
            $permit->id,
            'Наряд-допуск: '.$this->stringValue($permit->permit_number),
            $content,
            [
                'status' => $this->scalarValue($permit->status),
                'risk_level' => $this->scalarValue($permit->risk_level),
                'project_id' => $permit->project_id,
                'valid_until' => $this->dateTimeValue($permit->valid_until),
            ],
            $permit->updated_at
        );
    }

    private function briefingChunk(SafetyBriefing $briefing): RagChunkData
    {
        $content = $this->lines([
            'Инструктаж: '.$this->stringValue($briefing->briefing_number),
            'Проект: '.$this->stringValue($briefing->project?->name),
            'Название: '.$this->stringValue($briefing->title),
            'Тип: '.$this->stringValue($briefing->briefing_type),
            'Дата: '.$this->dateTimeValue($briefing->conducted_at),
            'Локация: '.$this->stringValue($briefing->location_name),
            'Проводил: '.$this->stringValue($briefing->conductedByUser?->name),
            'Темы: '.$this->arrayValue($briefing->topics),
            'Участников: '.$this->numberValue($briefing->participants_count ?? 0, 0),
            'Заметки: '.$this->stringValue($briefing->notes),
        ]);

        return $this->chunk(
            $briefing->organization_id,
            $briefing->project_id,
            'safety_briefing',
            $briefing->id,
            'Инструктаж: '.$this->stringValue($briefing->briefing_number),
            $content,
            [
                'project_id' => $briefing->project_id,
                'briefing_type' => $this->scalarValue($briefing->briefing_type),
                'conducted_at' => $this->dateTimeValue($briefing->conducted_at),
                'participants_count' => (int) ($briefing->participants_count ?? 0),
            ],
            $briefing->updated_at
        );
    }

    private function correctiveActionChunk(SafetyCorrectiveAction $action): RagChunkData
    {
        $content = $this->lines([
            'Корректирующее действие: '.$this->stringValue($action->action_number),
            'Проект: '.$this->stringValue($action->project?->name),
            'Название: '.$this->stringValue($action->title),
            'Источник: '.$this->stringValue($action->source_type),
            'Серьезность: '.$this->stringValue($action->severity),
            'Статус: '.$this->stringValue($action->status),
            'Срок: '.$this->dateValue($action->due_date),
            'Ответственный: '.$this->stringValue($action->assignedUser?->name),
            'Инцидент: '.$this->stringValue($action->incident?->incident_number),
            'Нарушение: '.$this->stringValue($action->violation?->violation_number),
            'Описание: '.$this->stringValue($action->description),
            'Решение: '.$this->stringValue($action->resolution_comment),
            'Проверка: '.$this->stringValue($action->verification_comment),
        ]);

        return $this->chunk(
            $action->organization_id,
            $action->project_id,
            'safety_corrective_action',
            $action->id,
            'Корректирующее действие: '.$this->stringValue($action->action_number),
            $content,
            [
                'status' => $this->scalarValue($action->status),
                'severity' => $this->scalarValue($action->severity),
                'project_id' => $action->project_id,
                'incident_id' => $action->incident_id,
                'violation_id' => $action->violation_id,
                'due_date' => $this->dateValue($action->due_date),
            ],
            $action->updated_at
        );
    }

    private function inspectionChunk(SafetyInspection $inspection): RagChunkData
    {
        $content = $this->lines([
            'Проверка охраны труда: '.$this->stringValue($inspection->inspection_number),
            'Проект: '.$this->stringValue($inspection->project?->name),
            'Название: '.$this->stringValue($inspection->title),
            'Тип: '.$this->stringValue($inspection->inspection_type),
            'Статус: '.$this->stringValue($inspection->status),
            'Результат: '.$this->stringValue($inspection->result),
            'Риск: '.$this->stringValue($inspection->risk_level),
            'Локация: '.$this->stringValue($inspection->location_name),
            'Плановая дата: '.$this->dateTimeValue($inspection->planned_at),
            'Дата проведения: '.$this->dateTimeValue($inspection->conducted_at),
            'Проводил: '.$this->stringValue($inspection->conductedByUser?->name),
            'Пункты чек-листа: '.$this->numberValue($inspection->items_count ?? 0, 0),
            'Замечания: '.$this->numberValue($inspection->findings_count ?? 0, 0),
            'Итог: '.$this->stringValue($inspection->summary),
        ]);

        return $this->chunk(
            $inspection->organization_id,
            $inspection->project_id,
            'safety_inspection',
            $inspection->id,
            'Проверка ОТ: '.$this->stringValue($inspection->inspection_number),
            $content,
            [
                'status' => $this->scalarValue($inspection->status),
                'result' => $this->scalarValue($inspection->result),
                'inspection_type' => $this->scalarValue($inspection->inspection_type),
                'project_id' => $inspection->project_id,
                'conducted_at' => $this->dateTimeValue($inspection->conducted_at),
                'findings_count' => (int) ($inspection->findings_count ?? 0),
            ],
            $inspection->updated_at
        );
    }

    private function inspectionFindingChunk(SafetyInspectionFinding $finding): RagChunkData
    {
        $content = $this->lines([
            'Замечание проверки охраны труда: '.$this->stringValue($finding->finding_number),
            'Проект: '.$this->stringValue($finding->project?->name),
            'Проверка: '.$this->stringValue($finding->inspection?->inspection_number),
            'Название: '.$this->stringValue($finding->title),
            'Серьезность: '.$this->stringValue($finding->severity),
            'Статус: '.$this->stringValue($finding->status),
            'Срок устранения: '.$this->dateValue($finding->due_date),
            'Ответственный: '.$this->stringValue($finding->assignedUser?->name),
            'Описание: '.$this->stringValue($finding->description),
            'Решение: '.$this->stringValue($finding->resolution_comment),
        ]);

        return $this->chunk(
            $finding->organization_id,
            $finding->project_id,
            'safety_inspection_finding',
            $finding->id,
            'Замечание ОТ: '.$this->stringValue($finding->finding_number),
            $content,
            [
                'status' => $this->scalarValue($finding->status),
                'severity' => $this->scalarValue($finding->severity),
                'project_id' => $finding->project_id,
                'inspection_id' => $finding->inspection_id,
                'due_date' => $this->dateValue($finding->due_date),
            ],
            $finding->updated_at
        );
    }

    private function incidents(int $organizationId, ?int $projectId): iterable
    {
        return SafetyIncident::query()
            ->with(['project', 'assignedUser'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function violations(int $organizationId, ?int $projectId): iterable
    {
        return SafetyViolation::query()
            ->with(['project', 'assignedUser'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function permits(int $organizationId, ?int $projectId): iterable
    {
        return SafetyWorkPermit::query()
            ->with(['project', 'responsibleUser'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function briefings(int $organizationId, ?int $projectId): iterable
    {
        return SafetyBriefing::query()
            ->with(['project', 'conductedByUser'])
            ->withCount('participants')
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function correctiveActions(int $organizationId, ?int $projectId): iterable
    {
        return SafetyCorrectiveAction::query()
            ->with(['project', 'incident', 'violation', 'assignedUser'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function inspections(int $organizationId, ?int $projectId): iterable
    {
        return SafetyInspection::query()
            ->with(['project', 'conductedByUser'])
            ->withCount(['items', 'findings'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function inspectionFindings(int $organizationId, ?int $projectId): iterable
    {
        return SafetyInspectionFinding::query()
            ->with(['project', 'inspection', 'assignedUser'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function singleIncident(int $organizationId, string|int $entityId): array
    {
        $incident = SafetyIncident::query()
            ->with(['project', 'assignedUser'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $incident instanceof SafetyIncident ? [$this->incidentChunk($incident)] : [];
    }

    private function singleViolation(int $organizationId, string|int $entityId): array
    {
        $violation = SafetyViolation::query()
            ->with(['project', 'assignedUser'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $violation instanceof SafetyViolation ? [$this->violationChunk($violation)] : [];
    }

    private function singlePermit(int $organizationId, string|int $entityId): array
    {
        $permit = SafetyWorkPermit::query()
            ->with(['project', 'responsibleUser'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $permit instanceof SafetyWorkPermit ? [$this->permitChunk($permit)] : [];
    }

    private function singleBriefing(int $organizationId, string|int $entityId): array
    {
        $briefing = SafetyBriefing::query()
            ->with(['project', 'conductedByUser'])
            ->withCount('participants')
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $briefing instanceof SafetyBriefing ? [$this->briefingChunk($briefing)] : [];
    }

    private function singleCorrectiveAction(int $organizationId, string|int $entityId): array
    {
        $action = SafetyCorrectiveAction::query()
            ->with(['project', 'incident', 'violation', 'assignedUser'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $action instanceof SafetyCorrectiveAction ? [$this->correctiveActionChunk($action)] : [];
    }

    private function singleInspection(int $organizationId, string|int $entityId): array
    {
        $inspection = SafetyInspection::query()
            ->with(['project', 'conductedByUser'])
            ->withCount(['items', 'findings'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $inspection instanceof SafetyInspection ? [$this->inspectionChunk($inspection)] : [];
    }

    private function singleInspectionFinding(int $organizationId, string|int $entityId): array
    {
        $finding = SafetyInspectionFinding::query()
            ->with(['project', 'inspection', 'assignedUser'])
            ->forOrganization($organizationId)
            ->where('id', $entityId)
            ->first();

        return $finding instanceof SafetyInspectionFinding ? [$this->inspectionFindingChunk($finding)] : [];
    }

    private function chunk(
        int $organizationId,
        ?int $projectId,
        string $entityType,
        int $entityId,
        string $title,
        string $content,
        array $metadata,
        ?DateTimeInterface $updatedAt
    ): RagChunkData {
        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: $entityType,
            entityId: $entityId,
            title: $title,
            content: $content,
            metadata: $metadata,
            updatedAt: $updatedAt
        );
    }
}
