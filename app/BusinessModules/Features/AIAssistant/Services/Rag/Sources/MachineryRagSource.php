<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use DateTimeInterface;

final class MachineryRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'machinery';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->assets($organizationId, $projectId) as $asset) {
            yield $this->assetChunk($asset);
        }

        foreach ($this->assignments($organizationId, $projectId) as $assignment) {
            yield $this->assignmentChunk($assignment);
        }

        foreach ($this->shiftReports($organizationId, $projectId) as $report) {
            yield $this->shiftReportChunk($report);
        }

        foreach ($this->downtimes($organizationId, $projectId) as $downtime) {
            yield $this->downtimeChunk($downtime);
        }

        foreach ($this->maintenanceOrders($organizationId, $projectId) as $order) {
            yield $this->maintenanceOrderChunk($order);
        }

        foreach ($this->fuelIssues($organizationId, $projectId) as $issue) {
            yield $this->fuelIssueChunk($issue);
        }

        foreach ($this->productionRecords($organizationId, $projectId) as $record) {
            yield $this->productionRecordChunk($record);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'machinery_asset' => $this->singleAsset($organizationId, $entityId),
            'machinery_assignment' => $this->singleAssignment($organizationId, $entityId),
            'machinery_shift_report' => $this->singleShiftReport($organizationId, $entityId),
            'machinery_downtime' => $this->singleDowntime($organizationId, $entityId),
            'machinery_maintenance_order' => $this->singleMaintenanceOrder($organizationId, $entityId),
            'machinery_fuel_issue' => $this->singleFuelIssue($organizationId, $entityId),
            'machinery_production_record' => $this->singleProductionRecord($organizationId, $entityId),
            default => [],
        };
    }

    private function assetChunk(MachineryAsset $asset): RagChunkData
    {
        $content = $this->lines([
            'Единица техники: '.$this->stringValue($asset->asset_code),
            'Название: '.$this->stringValue($asset->name),
            'Инвентарный номер: '.$this->stringValue($asset->inventory_number),
            'Проект: '.$this->stringValue($asset->currentProject?->name),
            'Задача графика: '.$this->stringValue($asset->currentScheduleTask?->name),
            'Статус: '.$this->stringValue($asset->status),
            'Владение: '.$this->stringValue($asset->ownership_type),
            'Стоимость часа: '.$this->moneyValue($asset->operating_cost_per_hour),
            'Топливо: '.$this->stringValue($asset->fuel_type),
            'Расход топлива: '.$this->numberValue($asset->fuel_consumption_rate),
            'Наработка: '.$this->numberValue($asset->meter_hours),
        ]);

        return $this->chunk(
            $asset->organization_id,
            $asset->current_project_id !== null ? (int) $asset->current_project_id : null,
            'machinery_asset',
            $asset->id,
            'Техника: '.$this->stringValue($asset->name),
            $content,
            [
                'status' => $this->scalarValue($asset->status),
                'project_id' => $asset->current_project_id,
                'schedule_task_id' => $asset->current_schedule_task_id,
                'machinery_id' => $asset->machinery_id,
            ],
            $asset->updated_at
        );
    }

    private function assignmentChunk(MachineryAssignment $assignment): RagChunkData
    {
        $content = $this->lines([
            'Назначение техники: '.$this->stringValue($assignment->asset?->name),
            'Проект: '.$this->stringValue($assignment->project?->name),
            'Задача графика: '.$this->stringValue($assignment->scheduleTask?->name),
            'Статус: '.$this->stringValue($assignment->status),
            'Плановый старт: '.$this->dateTimeValue($assignment->planned_start_at),
            'Плановое окончание: '.$this->dateTimeValue($assignment->planned_end_at),
            'Фактический старт: '.$this->dateTimeValue($assignment->actual_start_at),
            'Фактическое окончание: '.$this->dateTimeValue($assignment->actual_end_at),
            'План часов: '.$this->numberValue($assignment->planned_hours, 2),
            'Заявитель: '.$this->stringValue($assignment->requestedBy?->name),
            'Комментарий: '.$this->stringValue($assignment->comment),
        ]);

        return $this->chunk(
            $assignment->organization_id,
            $assignment->project_id,
            'machinery_assignment',
            $assignment->id,
            'Назначение техники: '.$this->stringValue($assignment->asset?->name),
            $content,
            [
                'status' => $this->scalarValue($assignment->status),
                'project_id' => $assignment->project_id,
                'asset_id' => $assignment->asset_id,
                'schedule_task_id' => $assignment->schedule_task_id,
            ],
            $assignment->updated_at
        );
    }

    private function shiftReportChunk(MachineryShiftReport $report): RagChunkData
    {
        $content = $this->lines([
            'Сменный отчет техники: '.$this->stringValue($report->asset?->name),
            'Проект: '.$this->stringValue($report->project?->name),
            'Дата смены: '.$this->dateValue($report->report_date),
            'Статус: '.$this->stringValue($report->status),
            'План часов: '.$this->numberValue($report->planned_hours, 2),
            'Факт часов: '.$this->numberValue($report->actual_hours, 2),
            'Топливо: '.$this->numberValue($report->fuel_consumed, 3),
            'Счетчик старт: '.$this->numberValue($report->meter_start, 2),
            'Счетчик конец: '.$this->numberValue($report->meter_end, 2),
            'Отчитался: '.$this->stringValue($report->reportedBy?->name),
            'Простои: '.$this->numberValue($report->downtimes_count ?? 0, 0),
            'Описание работ: '.$this->stringValue($report->work_description),
            'Причина отклонения: '.$this->stringValue($report->rejection_reason),
        ]);

        return $this->chunk(
            $report->organization_id,
            $report->project_id,
            'machinery_shift_report',
            $report->id,
            'Сменный отчет техники: '.$this->dateValue($report->report_date),
            $content,
            [
                'status' => $this->scalarValue($report->status),
                'project_id' => $report->project_id,
                'asset_id' => $report->asset_id,
                'report_date' => $this->dateValue($report->report_date),
                'downtimes_count' => (int) ($report->downtimes_count ?? 0),
            ],
            $report->updated_at
        );
    }

    private function downtimeChunk(MachineryDowntime $downtime): RagChunkData
    {
        $content = $this->lines([
            'Простой техники: '.$this->stringValue($downtime->asset?->name),
            'Проект: '.$this->stringValue($downtime->project?->name),
            'Причина: '.$this->stringValue($downtime->reason),
            'Начало: '.$this->dateTimeValue($downtime->started_at),
            'Окончание: '.$this->dateTimeValue($downtime->ended_at),
            'Длительность минут: '.$this->numberValue($downtime->duration_minutes, 0),
            'Комментарий: '.$this->stringValue($downtime->comment),
        ]);

        return $this->chunk(
            $downtime->organization_id,
            $downtime->project_id,
            'machinery_downtime',
            $downtime->id,
            'Простой техники: '.$this->stringValue($downtime->reason),
            $content,
            [
                'project_id' => $downtime->project_id,
                'asset_id' => $downtime->asset_id,
                'shift_report_id' => $downtime->shift_report_id,
                'duration_minutes' => $downtime->duration_minutes,
            ],
            $downtime->updated_at
        );
    }

    private function maintenanceOrderChunk(MachineryMaintenanceOrder $order): RagChunkData
    {
        $content = $this->lines([
            'ТО техники: '.$this->stringValue($order->order_number),
            'Проект: '.$this->stringValue($order->project?->name),
            'Техника: '.$this->stringValue($order->asset?->name),
            'Название: '.$this->stringValue($order->title),
            'Тип: '.$this->stringValue($order->maintenance_type),
            'Приоритет: '.$this->stringValue($order->priority),
            'Статус: '.$this->stringValue($order->status),
            'Плановая дата: '.$this->dateTimeValue($order->planned_at),
            'Дата завершения: '.$this->dateTimeValue($order->completed_at),
            'Стоимость: '.$this->moneyValue($order->cost),
            'Заявитель: '.$this->stringValue($order->requestedBy?->name),
            'Описание: '.$this->stringValue($order->description),
            'Комментарий завершения: '.$this->stringValue($order->completion_comment),
        ]);

        return $this->chunk(
            $order->organization_id,
            $order->project_id,
            'machinery_maintenance_order',
            $order->id,
            'ТО техники: '.$this->stringValue($order->order_number),
            $content,
            [
                'status' => $this->scalarValue($order->status),
                'priority' => $this->scalarValue($order->priority),
                'project_id' => $order->project_id,
                'asset_id' => $order->asset_id,
                'planned_at' => $this->dateTimeValue($order->planned_at),
            ],
            $order->updated_at
        );
    }

    private function fuelIssueChunk(MachineryFuelIssue $issue): RagChunkData
    {
        $content = $this->lines([
            'Выдача топлива: '.$this->stringValue($issue->asset?->name),
            'Проект: '.$this->stringValue($issue->project?->name),
            'Дата: '.$this->dateTimeValue($issue->issued_at),
            'Тип топлива: '.$this->stringValue($issue->fuel_type),
            'Количество: '.$this->numberValue($issue->quantity).' '.$this->stringValue($issue->unit),
            'Стоимость: '.$this->moneyValue($issue->cost),
            'Выдал: '.$this->stringValue($issue->issuedBy?->name),
            'Комментарий: '.$this->stringValue($issue->comment),
        ]);

        return $this->chunk(
            $issue->organization_id,
            $issue->project_id,
            'machinery_fuel_issue',
            $issue->id,
            'Топливо для техники: '.$this->stringValue($issue->asset?->name),
            $content,
            [
                'project_id' => $issue->project_id,
                'asset_id' => $issue->asset_id,
                'fuel_type' => $this->scalarValue($issue->fuel_type),
                'issued_at' => $this->dateTimeValue($issue->issued_at),
            ],
            $issue->updated_at
        );
    }

    private function productionRecordChunk(MachineryProductionRecord $record): RagChunkData
    {
        $content = $this->lines([
            'Выработка техники: '.$this->stringValue($record->asset?->name),
            'Проект: '.$this->stringValue($record->project?->name),
            'Дата: '.$this->dateTimeValue($record->recorded_at),
            'Количество: '.$this->numberValue($record->quantity).' '.$this->stringValue($record->unit),
            'Зафиксировал: '.$this->stringValue($record->recordedBy?->name),
            'Комментарий: '.$this->stringValue($record->comment),
        ]);

        return $this->chunk(
            $record->organization_id,
            $record->project_id,
            'machinery_production_record',
            $record->id,
            'Выработка техники: '.$this->stringValue($record->asset?->name),
            $content,
            [
                'project_id' => $record->project_id,
                'asset_id' => $record->asset_id,
                'shift_report_id' => $record->shift_report_id,
                'recorded_at' => $this->dateTimeValue($record->recorded_at),
            ],
            $record->updated_at
        );
    }

    private function assets(int $organizationId, ?int $projectId): iterable
    {
        return MachineryAsset::query()
            ->with(['machinery', 'currentProject', 'currentScheduleTask'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('current_project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function assignments(int $organizationId, ?int $projectId): iterable
    {
        return MachineryAssignment::query()
            ->with(['asset', 'project', 'scheduleTask', 'requestedBy'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function shiftReports(int $organizationId, ?int $projectId): iterable
    {
        return MachineryShiftReport::query()
            ->with(['asset', 'project', 'assignment', 'reportedBy'])
            ->withCount('downtimes')
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function downtimes(int $organizationId, ?int $projectId): iterable
    {
        return MachineryDowntime::query()
            ->with(['asset', 'project', 'shiftReport'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function maintenanceOrders(int $organizationId, ?int $projectId): iterable
    {
        return MachineryMaintenanceOrder::query()
            ->with(['asset', 'project', 'requestedBy'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function fuelIssues(int $organizationId, ?int $projectId): iterable
    {
        return MachineryFuelIssue::query()
            ->with(['asset', 'project', 'issuedBy'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function productionRecords(int $organizationId, ?int $projectId): iterable
    {
        return MachineryProductionRecord::query()
            ->with(['asset', 'project', 'shiftReport', 'recordedBy'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function singleAsset(int $organizationId, string|int $entityId): array
    {
        $asset = MachineryAsset::query()->with(['machinery', 'currentProject', 'currentScheduleTask'])->forOrganization($organizationId)->find($entityId);

        return $asset instanceof MachineryAsset ? [$this->assetChunk($asset)] : [];
    }

    private function singleAssignment(int $organizationId, string|int $entityId): array
    {
        $assignment = MachineryAssignment::query()->with(['asset', 'project', 'scheduleTask', 'requestedBy'])->forOrganization($organizationId)->find($entityId);

        return $assignment instanceof MachineryAssignment ? [$this->assignmentChunk($assignment)] : [];
    }

    private function singleShiftReport(int $organizationId, string|int $entityId): array
    {
        $report = MachineryShiftReport::query()->with(['asset', 'project', 'assignment', 'reportedBy'])->withCount('downtimes')->forOrganization($organizationId)->find($entityId);

        return $report instanceof MachineryShiftReport ? [$this->shiftReportChunk($report)] : [];
    }

    private function singleDowntime(int $organizationId, string|int $entityId): array
    {
        $downtime = MachineryDowntime::query()->with(['asset', 'project', 'shiftReport'])->forOrganization($organizationId)->find($entityId);

        return $downtime instanceof MachineryDowntime ? [$this->downtimeChunk($downtime)] : [];
    }

    private function singleMaintenanceOrder(int $organizationId, string|int $entityId): array
    {
        $order = MachineryMaintenanceOrder::query()->with(['asset', 'project', 'requestedBy'])->forOrganization($organizationId)->find($entityId);

        return $order instanceof MachineryMaintenanceOrder ? [$this->maintenanceOrderChunk($order)] : [];
    }

    private function singleFuelIssue(int $organizationId, string|int $entityId): array
    {
        $issue = MachineryFuelIssue::query()->with(['asset', 'project', 'issuedBy'])->forOrganization($organizationId)->find($entityId);

        return $issue instanceof MachineryFuelIssue ? [$this->fuelIssueChunk($issue)] : [];
    }

    private function singleProductionRecord(int $organizationId, string|int $entityId): array
    {
        $record = MachineryProductionRecord::query()->with(['asset', 'project', 'shiftReport', 'recordedBy'])->forOrganization($organizationId)->find($entityId);

        return $record instanceof MachineryProductionRecord ? [$this->productionRecordChunk($record)] : [];
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
