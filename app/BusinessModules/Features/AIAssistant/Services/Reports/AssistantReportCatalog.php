<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;

final class AssistantReportCatalog
{
    /**
     * @return AssistantReportDefinition[]
     */
    public function all(): array
    {
        return [
            new AssistantReportDefinition(
                id: 'project_profitability',
                capability: 'finance',
                label: 'рентабельность проектов',
                toolName: 'generate_profitability_report',
                aliases: ['отчет по рентабельности', 'рентабельность проектов', 'прибыльность проектов'],
                matchTerms: ['рентабельность', 'прибыльность', 'маржинальность', 'финансовая эффективность'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'work_completion',
                capability: 'production',
                label: 'выполнение работ',
                toolName: 'generate_work_completion_report',
                aliases: ['отчет по выполнению работ', 'выполнение работ', 'закрытые работы'],
                matchTerms: ['выполнение работ', 'выполненные работы', 'закрытые работы', 'объем работ'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'material_movements',
                capability: 'warehouse',
                label: 'движение материалов',
                toolName: 'generate_material_movements_report',
                aliases: ['отчет по движению материалов', 'движение материалов', 'расход материалов'],
                matchTerms: ['движение материалов', 'расход материалов', 'приход материалов', 'материалы за период'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'contractor_settlements',
                capability: 'contracts',
                label: 'расчеты с подрядчиками',
                toolName: 'generate_contractor_settlements_report',
                aliases: ['отчет по расчетам с подрядчиками', 'расчеты с подрядчиками', 'взаиморасчеты с подрядчиками'],
                matchTerms: ['расчеты с подрядчиками', 'взаиморасчеты', 'задолженность подрядчиков', 'подрядчики'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('contractor_id', 'contractor')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'warehouse_stock',
                capability: 'warehouse',
                label: 'остатки склада',
                toolName: 'generate_warehouse_stock_report',
                aliases: ['отчет по остаткам склада', 'остатки склада', 'складские остатки'],
                matchTerms: ['остатки склада', 'складские остатки', 'наличие на складе', 'остатки материалов'],
                requiredSlots: [],
                optionalSlots: [$this->slot('warehouse_id', 'warehouse')],
                permissions: ['reports.view', 'warehouse.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'time_tracking',
                capability: 'workforce',
                label: 'трудозатраты',
                toolName: 'generate_time_tracking_report',
                aliases: ['отчет по трудозатратам', 'трудозатраты', 'учет рабочего времени'],
                matchTerms: ['трудозатраты', 'рабочее время', 'табель', 'часы сотрудников'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project'), $this->slot('user_id', 'user')],
                permissions: ['reports.view', 'time_tracking.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'contract_payments',
                capability: 'payments',
                label: 'платежи по договорам',
                toolName: 'generate_contract_payments_report',
                aliases: ['отчет по платежам договоров', 'платежи по договорам', 'оплаты по договорам'],
                matchTerms: ['платежи по договорам', 'оплаты по договорам', 'договорные платежи', 'платежи договоров'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            new AssistantReportDefinition(
                id: 'project_timelines',
                capability: 'schedules',
                label: 'график работ',
                toolName: 'generate_project_timelines_report',
                aliases: ['отчет по графику работ', 'график работ', 'сроки проекта', 'отставание от графика'],
                matchTerms: ['график работ', 'сроки проекта', 'таймлайн', 'отставание от графика', 'этапы работ'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'schedule-management.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
            $this->operationalReport(
                id: 'projects_summary',
                capability: 'projects',
                label: 'сводка по проектам',
                aliases: ['отчет по проектам', 'отчет по проекту', 'pdf-отчет по проекту', 'pdf отчет по проекту', 'сводка по проектам', 'сводку по проектам', 'общий отчет по проектам', 'любой отчет', 'любые отчеты'],
                matchTerms: ['сводка по проектам', 'сводку по проектам', 'проект за период', 'проекты за период', 'портфель проектов', 'общий отчет', 'любой отчет']
            ),
            $this->operationalReport(
                id: 'procurement_requests',
                capability: 'procurement',
                label: 'заявки на закупку',
                aliases: ['отчет по заявкам на закупку', 'заявки на закупку', 'потребность в закупках'],
                matchTerms: ['заявки на закупку', 'закупочные заявки', 'потребность в закупках', 'заявки снабжения']
            ),
            $this->operationalReport(
                id: 'purchase_orders',
                capability: 'procurement',
                label: 'заказы поставщикам',
                aliases: ['отчет по заказам поставщикам', 'заказы поставщикам', 'заказы на поставку'],
                matchTerms: ['заказы поставщикам', 'заказы на поставку', 'purchase orders', 'поставки по заказам']
            ),
            $this->operationalReport(
                id: 'supplier_proposals',
                capability: 'procurement',
                label: 'предложения поставщиков',
                aliases: ['отчет по предложениям поставщиков', 'предложения поставщиков', 'коммерческие предложения поставщиков'],
                matchTerms: ['предложения поставщиков', 'коммерческие предложения', 'офферы поставщиков', 'сравнение поставщиков']
            ),
            $this->operationalReport(
                id: 'site_requests',
                capability: 'production',
                label: 'заявки со стройплощадки',
                aliases: ['отчет по заявкам со стройплощадки', 'заявки со стройплощадки', 'заявки с объекта'],
                matchTerms: ['заявки со стройплощадки', 'заявки с объекта', 'заявки участка', 'site requests']
            ),
            $this->operationalReport(
                id: 'estimates_summary',
                capability: 'finance',
                label: 'сводка по сметам',
                aliases: ['отчет по сметам', 'сводка по сметам', 'сметы и статусы'],
                matchTerms: ['сметы', 'сводка по сметам', 'статусы смет', 'estimate']
            ),
            $this->operationalReport(
                id: 'quality_defects',
                capability: 'quality',
                label: 'дефекты качества',
                aliases: ['отчет по дефектам качества', 'дефекты качества', 'замечания по качеству'],
                matchTerms: ['дефекты качества', 'замечания качества', 'quality defects', 'несоответствия']
            ),
            $this->operationalReport(
                id: 'safety_incidents',
                capability: 'safety',
                label: 'инциденты безопасности',
                aliases: ['отчет по инцидентам безопасности', 'инциденты безопасности', 'нарушения охраны труда'],
                matchTerms: ['инциденты безопасности', 'нарушения безопасности', 'охрана труда', 'safety incidents']
            ),
            $this->operationalReport(
                id: 'machinery_utilization',
                capability: 'machinery',
                label: 'работа техники',
                aliases: ['отчет по работе техники', 'работа техники', 'простои техники'],
                matchTerms: ['работа техники', 'простои техники', 'использование техники', 'машины и механизмы']
            ),
            $this->operationalReport(
                id: 'workforce_attendance',
                capability: 'workforce',
                label: 'посещаемость сотрудников',
                aliases: ['отчет по посещаемости сотрудников', 'посещаемость сотрудников', 'явка персонала'],
                matchTerms: ['посещаемость сотрудников', 'явка персонала', 'табель посещаемости', 'пропуски сотрудников']
            ),
            new AssistantReportDefinition(
                id: 'generic_rag',
                capability: 'reports',
                label: 'отчет из базы знаний',
                toolName: 'generate_rag_pdf_report',
                aliases: ['отчет из базы знаний', 'pdf отчет по теме', 'pdf-отчет по теме', 'отчет по теме из базы знаний', 'отчет с источниками из базы знаний'],
                matchTerms: ['база знаний', 'из базы знаний', 'по теме', 'с источниками', 'rag'],
                requiredSlots: [],
                optionalSlots: [$this->periodSlot(), $this->slot('project_id', 'project'), $this->slot('query', 'text')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'pdf',
                defaultFormat: 'pdf',
                formats: ['pdf']
            ),
        ];
    }

    public function findById(string $id): ?AssistantReportDefinition
    {
        $normalizedId = str_starts_with($id, 'report.') ? substr($id, 7) : $id;

        foreach ($this->all() as $definition) {
            if ($definition->id === $normalizedId) {
                return $definition;
            }
        }

        return null;
    }

    public function findByToolName(string $toolName): ?AssistantReportDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->toolName === $toolName) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function toolNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (AssistantReportDefinition $definition): string => $definition->toolName,
            $this->all()
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function agentTasks(): array
    {
        return array_map(
            static fn (AssistantReportDefinition $definition): array => $definition->toAgentTask(),
            $this->all()
        );
    }

    /**
     * @return array{name: string, type: string, question: string}
     */
    private function periodSlot(): array
    {
        return ['name' => 'period', 'type' => 'period', 'question' => ''];
    }

    /**
     * @return array{name: string, type: string}
     */
    private function slot(string $name, string $type): array
    {
        return ['name' => $name, 'type' => $type];
    }

    private function operationalReport(
        string $id,
        string $capability,
        string $label,
        array $aliases,
        array $matchTerms
    ): AssistantReportDefinition {
        return new AssistantReportDefinition(
            id: $id,
            capability: $capability,
            label: $label,
            toolName: 'generate_operational_pdf_report',
            aliases: $aliases,
            matchTerms: $matchTerms,
            requiredSlots: [],
            optionalSlots: [$this->periodSlot(), $this->slot('project_id', 'project')],
            permissions: ['reports.view', 'admin.reports.view'],
            artifactType: 'pdf',
            defaultFormat: 'pdf',
            formats: ['pdf']
        );
    }
}
