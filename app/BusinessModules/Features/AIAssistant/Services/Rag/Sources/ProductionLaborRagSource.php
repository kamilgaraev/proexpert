<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine;
use DateTimeInterface;

final class ProductionLaborRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'production_labor';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->workOrders($organizationId, $projectId) as $workOrder) {
            yield $this->workOrderChunk($workOrder);
        }

        foreach ($this->workOrderLines($organizationId, $projectId) as $line) {
            yield $this->workOrderLineChunk($line);
        }

        foreach ($this->timesheets($organizationId, $projectId) as $timesheet) {
            yield $this->timesheetChunk($timesheet);
        }

        foreach ($this->timesheetEntries($organizationId, $projectId) as $entry) {
            yield $this->timesheetEntryChunk($entry);
        }

        foreach ($this->outputEntries($organizationId, $projectId) as $entry) {
            yield $this->outputEntryChunk($entry);
        }

        foreach ($this->payrollAccruals($organizationId, $projectId) as $accrual) {
            yield $this->payrollAccrualChunk($accrual);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'production_labor_work_order' => $this->singleWorkOrder($organizationId, $entityId),
            'production_labor_work_order_line' => $this->singleWorkOrderLine($organizationId, $entityId),
            'production_labor_timesheet' => $this->singleTimesheet($organizationId, $entityId),
            'production_labor_timesheet_entry' => $this->singleTimesheetEntry($organizationId, $entityId),
            'production_labor_output_entry' => $this->singleOutputEntry($organizationId, $entityId),
            'production_labor_payroll_accrual' => $this->singlePayrollAccrual($organizationId, $entityId),
            default => [],
        };
    }

    private function workOrderChunk(ProductionLaborWorkOrder $workOrder): RagChunkData
    {
        $content = $this->lines([
            'Наряд на работы: '.$this->stringValue($workOrder->order_number),
            'Проект: '.$this->stringValue($workOrder->project?->name),
            'Название: '.$this->stringValue($workOrder->title),
            'Задача графика: '.$this->stringValue($workOrder->scheduleTask?->name),
            'Подрядчик: '.$this->stringValue($workOrder->contractor?->name),
            'Исполнитель: '.$this->stringValue($workOrder->assignee_name),
            'Тип исполнителя: '.$this->stringValue($workOrder->assignee_type),
            'Статус: '.$this->stringValue($workOrder->status),
            'Плановый старт: '.$this->dateValue($workOrder->planned_start_date),
            'Плановое завершение: '.$this->dateValue($workOrder->planned_finish_date),
            'Выдан: '.$this->dateTimeValue($workOrder->issued_at),
            'Принят: '.$this->dateTimeValue($workOrder->accepted_at),
            'Закрыт: '.$this->dateTimeValue($workOrder->closed_at),
            'Позиции: '.$this->numberValue($workOrder->lines_count ?? 0, 0),
            'Выработка: '.$this->numberValue($workOrder->output_entries_count ?? 0, 0),
            'Табели: '.$this->numberValue($workOrder->timesheets_count ?? 0, 0),
            'Причина возврата: '.$this->stringValue($workOrder->return_reason),
        ]);

        return $this->chunk(
            $workOrder->organization_id,
            $workOrder->project_id,
            'production_labor_work_order',
            $workOrder->id,
            'Наряд: '.$this->stringValue($workOrder->order_number),
            $content,
            [
                'status' => $this->scalarValue($workOrder->status),
                'project_id' => $workOrder->project_id,
                'schedule_task_id' => $workOrder->schedule_task_id,
                'contractor_id' => $workOrder->contractor_id,
            ],
            $workOrder->updated_at
        );
    }

    private function workOrderLineChunk(ProductionLaborWorkOrderLine $line): RagChunkData
    {
        $workOrder = $line->workOrder;
        $content = $this->lines([
            'Позиция наряда: '.$this->stringValue($line->name),
            'Наряд: '.$this->stringValue($workOrder?->order_number),
            'Проект: '.$this->stringValue($workOrder?->project?->name),
            'Вид работ: '.$this->stringValue($line->workType?->name),
            'Задача графика: '.$this->stringValue($line->scheduleTask?->name),
            'Плановый объем: '.$this->numberValue($line->planned_quantity).' '.$this->stringValue($line->unit),
            'Принятый объем: '.$this->numberValue($line->accepted_quantity).' '.$this->stringValue($line->unit),
            'Ставка за единицу: '.$this->moneyValue($line->unit_rate),
            'План часов: '.$this->numberValue($line->planned_hours, 2),
            'Ставка часа: '.$this->moneyValue($line->hour_rate),
            'Основание оплаты: '.$this->stringValue($line->pay_basis),
            'Требуется наряд-допуск: '.$this->boolValue($line->requires_safety_permit),
        ]);

        return $this->chunk(
            $line->organization_id,
            $workOrder?->project_id !== null ? (int) $workOrder->project_id : null,
            'production_labor_work_order_line',
            $line->id,
            'Позиция наряда: '.$this->stringValue($line->name),
            $content,
            [
                'work_order_id' => $line->work_order_id,
                'project_id' => $workOrder?->project_id,
                'work_type_id' => $line->work_type_id,
                'schedule_task_id' => $line->schedule_task_id,
                'requires_safety_permit' => (bool) $line->requires_safety_permit,
            ],
            $line->updated_at
        );
    }

    private function timesheetChunk(ProductionLaborTimesheet $timesheet): RagChunkData
    {
        $content = $this->lines([
            'Табель выработки: '.$this->dateValue($timesheet->shift_date),
            'Проект: '.$this->stringValue($timesheet->project?->name),
            'Наряд: '.$this->stringValue($timesheet->workOrder?->order_number),
            'Статус: '.$this->stringValue($timesheet->status),
            'Создал: '.$this->stringValue($timesheet->createdBy?->name),
            'Строк: '.$this->numberValue($timesheet->entries_count ?? 0, 0),
        ]);

        return $this->chunk(
            $timesheet->organization_id,
            $timesheet->project_id,
            'production_labor_timesheet',
            $timesheet->id,
            'Табель: '.$this->dateValue($timesheet->shift_date),
            $content,
            [
                'status' => $this->scalarValue($timesheet->status),
                'project_id' => $timesheet->project_id,
                'work_order_id' => $timesheet->work_order_id,
                'shift_date' => $this->dateValue($timesheet->shift_date),
                'entries_count' => (int) ($timesheet->entries_count ?? 0),
            ],
            $timesheet->updated_at
        );
    }

    private function timesheetEntryChunk(ProductionLaborTimesheetEntry $entry): RagChunkData
    {
        $timesheet = $entry->timesheet;
        $content = $this->lines([
            'Строка табеля: '.$this->stringValue($entry->worker_name),
            'Табель: '.$this->dateValue($timesheet?->shift_date),
            'Проект: '.$this->stringValue($timesheet?->project?->name),
            'Наряд: '.$this->stringValue($timesheet?->workOrder?->order_number),
            'Позиция: '.$this->stringValue($entry->line?->name),
            'Сотрудник: '.$this->stringValue($entry->user?->name),
            'Часы: '.$this->numberValue($entry->hours, 2),
            'Включать в оплату: '.$this->boolValue($entry->include_in_payroll),
            'Наряд-допуск: '.$this->stringValue($entry->safety_permit_reference),
        ]);

        return $this->chunk(
            $entry->organization_id,
            $timesheet?->project_id !== null ? (int) $timesheet->project_id : null,
            'production_labor_timesheet_entry',
            $entry->id,
            'Строка табеля: '.$this->stringValue($entry->worker_name),
            $content,
            [
                'timesheet_id' => $entry->timesheet_id,
                'work_order_line_id' => $entry->work_order_line_id,
                'project_id' => $timesheet?->project_id,
                'hours' => $entry->hours,
                'include_in_payroll' => (bool) $entry->include_in_payroll,
            ],
            $entry->updated_at
        );
    }

    private function outputEntryChunk(ProductionLaborOutputEntry $entry): RagChunkData
    {
        $content = $this->lines([
            'Выработка: '.$this->stringValue($entry->line?->name),
            'Проект: '.$this->stringValue($entry->project?->name),
            'Наряд: '.$this->stringValue($entry->workOrder?->order_number),
            'Задача графика: '.$this->stringValue($entry->scheduleTask?->name),
            'Дата работ: '.$this->dateValue($entry->work_date),
            'Количество: '.$this->numberValue($entry->quantity),
            'Часы: '.$this->numberValue($entry->hours, 2),
            'Статус: '.$this->stringValue($entry->status),
            'Зафиксировал: '.$this->stringValue($entry->recordedBy?->name),
            'Утвердил: '.$this->stringValue($entry->approvedBy?->name),
            'Комментарий: '.$this->stringValue($entry->comment),
        ]);

        return $this->chunk(
            $entry->organization_id,
            $entry->project_id,
            'production_labor_output_entry',
            $entry->id,
            'Выработка: '.$this->dateValue($entry->work_date),
            $content,
            [
                'status' => $this->scalarValue($entry->status),
                'project_id' => $entry->project_id,
                'work_order_id' => $entry->work_order_id,
                'work_order_line_id' => $entry->work_order_line_id,
                'schedule_task_id' => $entry->schedule_task_id,
                'work_date' => $this->dateValue($entry->work_date),
            ],
            $entry->updated_at
        );
    }

    private function payrollAccrualChunk(ProductionLaborPayrollAccrual $accrual): RagChunkData
    {
        $content = $this->lines([
            'Начисление по выработке: '.$this->stringValue($accrual->line?->name),
            'Проект: '.$this->stringValue($accrual->project?->name),
            'Наряд: '.$this->stringValue($accrual->workOrder?->order_number),
            'Задача графика: '.$this->stringValue($accrual->scheduleTask?->name),
            'Период: '.$this->dateValue($accrual->period_start).' - '.$this->dateValue($accrual->period_end),
            'Принятый объем: '.$this->numberValue($accrual->accepted_quantity),
            'Принятые часы: '.$this->numberValue($accrual->accepted_hours, 2),
            'Сумма: '.$this->moneyValue($accrual->amount),
            'Статус: '.$this->stringValue($accrual->status),
            'Утверждено: '.$this->dateTimeValue($accrual->approved_at),
            'Утвердил: '.$this->stringValue($accrual->approvedBy?->name),
        ]);

        return $this->chunk(
            $accrual->organization_id,
            $accrual->project_id,
            'production_labor_payroll_accrual',
            $accrual->id,
            'Начисление по выработке: '.$this->moneyValue($accrual->amount),
            $content,
            [
                'status' => $this->scalarValue($accrual->status),
                'project_id' => $accrual->project_id,
                'work_order_id' => $accrual->work_order_id,
                'work_order_line_id' => $accrual->work_order_line_id,
                'period_start' => $this->dateValue($accrual->period_start),
                'period_end' => $this->dateValue($accrual->period_end),
                'amount' => $accrual->amount,
            ],
            $accrual->updated_at
        );
    }

    private function workOrders(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborWorkOrder::query()
            ->with(['project', 'scheduleTask', 'contractor'])
            ->withCount(['lines', 'outputEntries', 'timesheets'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function workOrderLines(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborWorkOrderLine::query()
            ->with(['workOrder.project', 'workType', 'estimateItem', 'scheduleTask'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas('workOrder', static fn ($workOrderQuery) => $workOrderQuery->where('project_id', $projectId)))
            ->orderBy('id')
            ->cursor();
    }

    private function timesheets(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborTimesheet::query()
            ->with(['workOrder', 'project', 'createdBy'])
            ->withCount('entries')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function timesheetEntries(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborTimesheetEntry::query()
            ->with(['timesheet.project', 'timesheet.workOrder', 'line', 'user', 'employee'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas('timesheet', static fn ($timesheetQuery) => $timesheetQuery->where('project_id', $projectId)))
            ->orderBy('id')
            ->cursor();
    }

    private function outputEntries(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborOutputEntry::query()
            ->with(['workOrder', 'line', 'project', 'scheduleTask', 'recordedBy', 'approvedBy'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function payrollAccruals(int $organizationId, ?int $projectId): iterable
    {
        return ProductionLaborPayrollAccrual::query()
            ->with(['workOrder', 'line', 'project', 'scheduleTask', 'approvedBy'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function singleWorkOrder(int $organizationId, string|int $entityId): array
    {
        $workOrder = ProductionLaborWorkOrder::query()->with(['project', 'scheduleTask', 'contractor'])->withCount(['lines', 'outputEntries', 'timesheets'])->forOrganization($organizationId)->find($entityId);

        return $workOrder instanceof ProductionLaborWorkOrder ? [$this->workOrderChunk($workOrder)] : [];
    }

    private function singleWorkOrderLine(int $organizationId, string|int $entityId): array
    {
        $line = ProductionLaborWorkOrderLine::query()->with(['workOrder.project', 'workType', 'estimateItem', 'scheduleTask'])->forOrganization($organizationId)->find($entityId);

        return $line instanceof ProductionLaborWorkOrderLine ? [$this->workOrderLineChunk($line)] : [];
    }

    private function singleTimesheet(int $organizationId, string|int $entityId): array
    {
        $timesheet = ProductionLaborTimesheet::query()->with(['workOrder', 'project', 'createdBy'])->withCount('entries')->where('organization_id', $organizationId)->find($entityId);

        return $timesheet instanceof ProductionLaborTimesheet ? [$this->timesheetChunk($timesheet)] : [];
    }

    private function singleTimesheetEntry(int $organizationId, string|int $entityId): array
    {
        $entry = ProductionLaborTimesheetEntry::query()->with(['timesheet.project', 'timesheet.workOrder', 'line', 'user', 'employee'])->where('organization_id', $organizationId)->find($entityId);

        return $entry instanceof ProductionLaborTimesheetEntry ? [$this->timesheetEntryChunk($entry)] : [];
    }

    private function singleOutputEntry(int $organizationId, string|int $entityId): array
    {
        $entry = ProductionLaborOutputEntry::query()->with(['workOrder', 'line', 'project', 'scheduleTask', 'recordedBy', 'approvedBy'])->where('organization_id', $organizationId)->find($entityId);

        return $entry instanceof ProductionLaborOutputEntry ? [$this->outputEntryChunk($entry)] : [];
    }

    private function singlePayrollAccrual(int $organizationId, string|int $entityId): array
    {
        $accrual = ProductionLaborPayrollAccrual::query()->with(['workOrder', 'line', 'project', 'scheduleTask', 'approvedBy'])->where('organization_id', $organizationId)->find($entityId);

        return $accrual instanceof ProductionLaborPayrollAccrual ? [$this->payrollAccrualChunk($accrual)] : [];
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
