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
                label: 'project_profitability',
                toolName: 'generate_profitability_report',
                aliases: ['otchet po rentabelnosti', 'rentabelnost proektov', 'pribylnost proektov'],
                matchTerms: ['rentabelnost', 'pribylnost', 'marzhinalnost', 'finansovaya effektivnost'],
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
                label: 'work_completion',
                toolName: 'generate_work_completion_report',
                aliases: ['otchet po vypolneniyu rabot', 'vypolnenie rabot', 'zakrytye raboty'],
                matchTerms: ['vypolnenie rabot', 'vypolnennye raboty', 'zakrytye raboty', 'obem rabot'],
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
                label: 'material_movements',
                toolName: 'generate_material_movements_report',
                aliases: ['otchet po dvizheniyu materialov', 'dvizhenie materialov', 'rashod materialov'],
                matchTerms: ['dvizhenie materialov', 'rashod materialov', 'prihod materialov', 'materialy za period'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'admin.reports.view'],
                artifactType: 'excel',
                defaultFormat: 'excel',
                formats: ['excel']
            ),
            new AssistantReportDefinition(
                id: 'contractor_settlements',
                capability: 'contracts',
                label: 'contractor_settlements',
                toolName: 'generate_contractor_settlements_report',
                aliases: ['otchet po raschetam s podryadchikami', 'raschety s podryadchikami', 'vzaimoraschety s podryadchikami'],
                matchTerms: ['raschety s podryadchikami', 'vzaimoraschety', 'zadolzhennost podryadchikov', 'podryadchiki'],
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
                label: 'warehouse_stock',
                toolName: 'generate_warehouse_stock_report',
                aliases: ['otchet po ostatkam sklada', 'ostatki sklada', 'skladskie ostatki'],
                matchTerms: ['ostatki sklada', 'skladskie ostatki', 'nalichie na sklade', 'ostatki materialov'],
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
                label: 'time_tracking',
                toolName: 'generate_time_tracking_report',
                aliases: ['otchet po trudozatratam', 'trudozatraty', 'uchet rabochego vremeni'],
                matchTerms: ['trudozatraty', 'rabochee vremya', 'tabel', 'chasy sotrudnikov'],
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
                label: 'contract_payments',
                toolName: 'generate_contract_payments_report',
                aliases: ['otchet po platezham dogovorov', 'platezhi po dogovoram', 'oplaty po dogovoram'],
                matchTerms: ['platezhi po dogovoram', 'oplaty po dogovoram', 'dogovornye platezhi', 'platezhi dogovorov'],
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
                label: 'project_timelines',
                toolName: 'generate_project_timelines_report',
                aliases: ['otchet po grafiku rabot', 'grafik rabot', 'sroki proekta', 'otstavanie ot grafika'],
                matchTerms: ['grafik rabot', 'sroki proekta', 'taymlayn', 'otstavanie ot grafika', 'etapy rabot'],
                requiredSlots: [$this->periodSlot()],
                optionalSlots: [$this->slot('project_id', 'project')],
                permissions: ['reports.view', 'schedule-management.view', 'admin.reports.view'],
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
        return array_map(
            static fn (AssistantReportDefinition $definition): string => $definition->toolName,
            $this->all()
        );
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
}
