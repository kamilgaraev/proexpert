<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseConstructionErpFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'construction_erp';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return $this->openCriticalDefects($context)
            ->merge($this->overdueReinspections($context))
            ->merge($this->missingExecutiveDocuments($context))
            ->merge($this->expiredWorkPermits($context))
            ->merge($this->openSafetyIncidents($context))
            ->merge($this->machineryDowntime($context))
            ->merge($this->laborUnderproduction($context))
            ->merge($this->unapprovedChanges($context))
            ->merge($this->unansweredRfi($context))
            ->merge($this->lookaheadHardConstraints($context))
            ->merge($this->handoverBlockedLocations($context))
            ->take($this->limit())
            ->values();
    }

    private function openCriticalDefects(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('quality_defects')) {
            return $this->empty();
        }

        return $this->table($context, 'quality_defects')
            ->leftJoin('projects', 'projects.id', '=', 'quality_defects.project_id')
            ->where('quality_defects.severity', 'critical')
            ->whereNotIn('quality_defects.status', ['resolved', 'verified', 'closed', 'cancelled'])
            ->limit($this->limit())
            ->get([
                'quality_defects.id',
                'quality_defects.project_id',
                'quality_defects.defect_number',
                'quality_defects.title',
                'quality_defects.status',
                'quality_defects.due_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'quality_defect:' . $row->id . ':critical_open',
                type: 'open_critical_defects',
                priority: 'critical',
                title: 'Критичный дефект открыт',
                text: 'Дефект "' . ($row->title ?? $row->defect_number) . '" требует закрытия до продолжения работ.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('quality_defect', (int) $row->id, 'Дефект ' . $row->defect_number, '/quality-control'),
                source: $this->key(),
                category: 'quality',
                status: $row->status,
                nextAction: 'Назначить ответственного, срок устранения и повторную проверку дефекта.',
                primaryAction: $this->action('Открыть качество', '/quality-control', 'quality-control.defects.view'),
                deadline: $row->due_date !== null ? (string) $row->due_date : null,
                ageDays: $this->ageDays($context, $row->due_date),
            ));
    }

    private function overdueReinspections(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('quality_defects')) {
            return $this->empty();
        }

        return $this->table($context, 'quality_defects')
            ->leftJoin('projects', 'projects.id', '=', 'quality_defects.project_id')
            ->where('quality_defects.inspection_required', true)
            ->whereNotIn('quality_defects.status', ['verified', 'closed', 'cancelled'])
            ->whereNotNull('quality_defects.due_date')
            ->whereDate('quality_defects.due_date', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get([
                'quality_defects.id',
                'quality_defects.project_id',
                'quality_defects.defect_number',
                'quality_defects.title',
                'quality_defects.status',
                'quality_defects.due_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'quality_defect:' . $row->id . ':overdue_reinspection',
                type: 'overdue_reinspections',
                priority: 'warning',
                title: 'Просрочена повторная проверка',
                text: 'По дефекту "' . ($row->title ?? $row->defect_number) . '" истек срок контрольной проверки.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('quality_defect', (int) $row->id, 'Дефект ' . $row->defect_number, '/quality-control'),
                source: $this->key(),
                category: 'quality',
                status: $row->status,
                nextAction: 'Провести повторную проверку и зафиксировать результат в качестве.',
                primaryAction: $this->action('Открыть качество', '/quality-control', 'quality-control.defects.view'),
                deadline: (string) $row->due_date,
                ageDays: $this->ageDays($context, $row->due_date),
            ));
    }

    private function missingExecutiveDocuments(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('executive_documents')) {
            return $this->empty();
        }

        return $this->table($context, 'executive_documents')
            ->leftJoin('projects', 'projects.id', '=', 'executive_documents.project_id')
            ->whereIn('executive_documents.status', ['draft', 'rejected', 'needs_revision', 'missing'])
            ->limit($this->limit())
            ->get([
                'executive_documents.id',
                'executive_documents.project_id',
                'executive_documents.title',
                'executive_documents.status',
                'executive_documents.inspection_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'executive_document:' . $row->id . ':missing',
                type: 'missing_executive_documents',
                priority: 'warning',
                title: 'Не хватает исполнительного документа',
                text: 'Документ "' . $row->title . '" не готов к передаче или требует доработки.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('executive_document', (int) $row->id, 'ИД #' . $row->id, '/executive-documentation'),
                source: $this->key(),
                category: 'documentation',
                status: $row->status,
                nextAction: 'Довести исполнительный документ до согласованного состояния.',
                primaryAction: $this->action('Открыть ИД', '/executive-documentation', 'executive-documentation.view'),
                deadline: $row->inspection_date !== null ? (string) $row->inspection_date : null,
                ageDays: $this->ageDays($context, $row->inspection_date),
            ));
    }

    private function expiredWorkPermits(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('safety_work_permits')) {
            return $this->empty();
        }

        return $this->table($context, 'safety_work_permits')
            ->leftJoin('projects', 'projects.id', '=', 'safety_work_permits.project_id')
            ->whereIn('safety_work_permits.status', ['active', 'approved'])
            ->whereDate('safety_work_permits.valid_until', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get([
                'safety_work_permits.id',
                'safety_work_permits.project_id',
                'safety_work_permits.permit_number',
                'safety_work_permits.title',
                'safety_work_permits.status',
                'safety_work_permits.valid_until',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'safety_work_permit:' . $row->id . ':expired',
                type: 'expired_work_permits',
                priority: 'critical',
                title: 'Наряд-допуск просрочен',
                text: 'Наряд "' . ($row->title ?? $row->permit_number) . '" активен после окончания срока действия.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('safety_work_permit', (int) $row->id, 'Наряд ' . $row->permit_number, '/safety-management'),
                source: $this->key(),
                category: 'safety',
                status: $row->status,
                nextAction: 'Остановить работы по просроченному допуску или продлить его по процедуре HSE.',
                primaryAction: $this->action('Открыть HSE', '/safety-management', 'safety-management.view'),
                deadline: (string) $row->valid_until,
                ageDays: $this->ageDays($context, $row->valid_until),
            ));
    }

    private function openSafetyIncidents(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('safety_incidents')) {
            return $this->empty();
        }

        return $this->table($context, 'safety_incidents')
            ->leftJoin('projects', 'projects.id', '=', 'safety_incidents.project_id')
            ->whereNotIn('safety_incidents.status', ['closed', 'cancelled'])
            ->limit($this->limit())
            ->get([
                'safety_incidents.id',
                'safety_incidents.project_id',
                'safety_incidents.incident_number',
                'safety_incidents.title',
                'safety_incidents.severity',
                'safety_incidents.status',
                'safety_incidents.occurred_at',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'safety_incident:' . $row->id . ':open',
                type: 'open_safety_incidents',
                priority: in_array($row->severity, ['critical', 'major'], true) ? 'critical' : 'warning',
                title: 'Открыт HSE-инцидент',
                text: 'Инцидент "' . ($row->title ?? $row->incident_number) . '" не закрыт по корректирующим действиям.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('safety_incident', (int) $row->id, 'Инцидент ' . $row->incident_number, '/safety-management'),
                source: $this->key(),
                category: 'safety',
                status: $row->status,
                nextAction: 'Проверить расследование, корректирующие действия и ответственного.',
                primaryAction: $this->action('Открыть HSE', '/safety-management', 'safety-management.view'),
                occurredAt: $this->dateString($row->occurred_at),
                ageDays: $this->ageDays($context, $row->occurred_at),
            ));
    }

    private function machineryDowntime(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('machinery_downtimes')) {
            return $this->empty();
        }

        return $this->table($context, 'machinery_downtimes')
            ->leftJoin('projects', 'projects.id', '=', 'machinery_downtimes.project_id')
            ->whereNull('machinery_downtimes.ended_at')
            ->limit($this->limit())
            ->get([
                'machinery_downtimes.id',
                'machinery_downtimes.project_id',
                'machinery_downtimes.reason',
                'machinery_downtimes.started_at',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'machinery_downtime:' . $row->id . ':open',
                type: 'machinery_downtime',
                priority: 'warning',
                title: 'Открыт простой техники',
                text: 'Простой техники по причине "' . $row->reason . '" еще не закрыт.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('machinery_downtime', (int) $row->id, 'Простой #' . $row->id, '/machinery-operations'),
                source: $this->key(),
                category: 'machinery',
                status: 'open',
                nextAction: 'Закрыть простой, подтвердить причину и влияние на сменный рапорт.',
                primaryAction: $this->action('Открыть технику', '/machinery-operations', 'machinery-operations.view'),
                occurredAt: $this->dateString($row->started_at),
                ageDays: $this->ageDays($context, $row->started_at),
            ));
    }

    private function laborUnderproduction(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('production_labor_work_order_lines') || !$this->hasTable('production_labor_work_orders')) {
            return $this->empty();
        }

        return $this->table($context, 'production_labor_work_order_lines')
            ->join('production_labor_work_orders', 'production_labor_work_orders.id', '=', 'production_labor_work_order_lines.work_order_id')
            ->leftJoin('projects', 'projects.id', '=', 'production_labor_work_orders.project_id')
            ->whereColumn('production_labor_work_order_lines.accepted_quantity', '<', 'production_labor_work_order_lines.planned_quantity')
            ->whereIn('production_labor_work_orders.status', ['issued', 'in_progress', 'submitted', 'accepted'])
            ->when($context->projectId !== null, fn ($query) => $query->where('production_labor_work_orders.project_id', $context->projectId))
            ->limit($this->limit())
            ->get([
                'production_labor_work_order_lines.id',
                'production_labor_work_order_lines.name',
                'production_labor_work_order_lines.planned_quantity',
                'production_labor_work_order_lines.accepted_quantity',
                'production_labor_work_orders.project_id',
                'production_labor_work_orders.order_number',
                'production_labor_work_orders.status',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'production_labor_line:' . $row->id . ':underproduction',
                type: 'labor_underproduction',
                priority: 'warning',
                title: 'Выработка ниже плана',
                text: 'По наряду ' . $row->order_number . ' принято ' . $row->accepted_quantity . ' из ' . $row->planned_quantity . '.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('production_labor_work_order_line', (int) $row->id, 'Строка наряда #' . $row->id, '/production-labor'),
                source: $this->key(),
                category: 'labor',
                status: $row->status,
                nextAction: 'Проверить фактическую выработку, табель и причину отставания бригады.',
                primaryAction: $this->action('Открыть наряды', '/production-labor', 'production-labor.view'),
                meta: [
                    'planned_quantity' => (float) $row->planned_quantity,
                    'accepted_quantity' => (float) $row->accepted_quantity,
                ],
            ));
    }

    private function unapprovedChanges(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('change_management_change_requests')) {
            return $this->empty();
        }

        return $this->table($context, 'change_management_change_requests')
            ->leftJoin('projects', 'projects.id', '=', 'change_management_change_requests.project_id')
            ->whereIn('change_management_change_requests.status', ['submitted', 'impact_assessment', 'internal_review', 'customer_review'])
            ->limit($this->limit())
            ->get([
                'change_management_change_requests.id',
                'change_management_change_requests.project_id',
                'change_management_change_requests.change_number',
                'change_management_change_requests.title',
                'change_management_change_requests.status',
                'change_management_change_requests.submitted_at',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'change_request:' . $row->id . ':unapproved',
                type: 'unapproved_changes',
                priority: $row->status === 'customer_review' ? 'critical' : 'warning',
                title: 'Изменение не утверждено',
                text: 'Изменение "' . ($row->title ?? $row->change_number) . '" находится в согласовании.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('change_request', (int) $row->id, 'Изменение ' . $row->change_number, '/change-management'),
                source: $this->key(),
                category: 'change',
                status: $row->status,
                nextAction: 'Довести изменение до решения и зафиксировать влияние на стоимость/срок.',
                primaryAction: $this->action('Открыть изменения', '/change-management', 'change-management.view'),
                occurredAt: $this->dateString($row->submitted_at),
                ageDays: $this->ageDays($context, $row->submitted_at),
            ));
    }

    private function unansweredRfi(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('change_management_rfis')) {
            return $this->empty();
        }

        return $this->table($context, 'change_management_rfis')
            ->leftJoin('projects', 'projects.id', '=', 'change_management_rfis.project_id')
            ->whereNull('change_management_rfis.answered_at')
            ->whereNotNull('change_management_rfis.response_due_date')
            ->whereDate('change_management_rfis.response_due_date', '<', $context->date->toDateString())
            ->whereNotIn('change_management_rfis.status', ['answered', 'accepted', 'closed', 'cancelled'])
            ->limit($this->limit())
            ->get([
                'change_management_rfis.id',
                'change_management_rfis.project_id',
                'change_management_rfis.rfi_number',
                'change_management_rfis.subject',
                'change_management_rfis.status',
                'change_management_rfis.response_due_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'rfi:' . $row->id . ':unanswered',
                type: 'unanswered_rfi',
                priority: 'warning',
                title: 'RFI без ответа',
                text: 'RFI "' . ($row->subject ?? $row->rfi_number) . '" просрочен по сроку ответа.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('rfi', (int) $row->id, 'RFI ' . $row->rfi_number, '/change-management'),
                source: $this->key(),
                category: 'change',
                status: $row->status,
                nextAction: 'Получить ответ на RFI или зафиксировать управленческое решение.',
                primaryAction: $this->action('Открыть RFI', '/change-management', 'change-management.view'),
                deadline: (string) $row->response_due_date,
                ageDays: $this->ageDays($context, $row->response_due_date),
            ));
    }

    private function lookaheadHardConstraints(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('work_constraints')) {
            return $this->empty();
        }

        return $this->table($context, 'work_constraints')
            ->leftJoin('projects', 'projects.id', '=', 'work_constraints.project_id')
            ->where('work_constraints.status', 'open')
            ->where('work_constraints.severity', 'hard')
            ->limit($this->limit())
            ->get([
                'work_constraints.id',
                'work_constraints.project_id',
                'work_constraints.schedule_id',
                'work_constraints.title',
                'work_constraints.status',
                'work_constraints.due_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'work_constraint:' . $row->id . ':hard_open',
                type: 'lookahead_hard_constraints',
                priority: 'critical',
                title: 'Жесткое ограничение lookahead',
                text: 'Ограничение "' . ($row->title ?? ('#' . $row->id)) . '" блокирует ближайший план.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('work_constraint', (int) $row->id, 'Ограничение #' . $row->id, '/schedules'),
                source: $this->key(),
                category: 'schedule',
                status: $row->status,
                nextAction: 'Снять жесткое ограничение или перепланировать работы в lookahead.',
                primaryAction: $this->action('Открыть lookahead', $this->lookaheadRoute((int) $row->project_id, (int) $row->schedule_id), 'schedule.view'),
                deadline: $row->due_date !== null ? (string) $row->due_date : null,
                ageDays: $this->ageDays($context, $row->due_date),
            ));
    }

    private function handoverBlockedLocations(ProjectPulseContext $context): Collection
    {
        if (
            !$this->hasTable('acceptance_scopes')
            || !$this->hasTable('handover_packages')
            || !$this->hasTable('handover_package_documents')
        ) {
            return $this->empty();
        }

        return $this->table($context, 'acceptance_scopes')
            ->leftJoin('projects', 'projects.id', '=', 'acceptance_scopes.project_id')
            ->whereIn('acceptance_scopes.status', ['findings_open', 'reopened', 'accepted'])
            ->where(function ($query): void {
                $query
                    ->whereIn('acceptance_scopes.status', ['findings_open', 'reopened'])
                    ->orWhereExists(function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('handover_packages')
                            ->join('handover_package_documents', 'handover_package_documents.handover_package_id', '=', 'handover_packages.id')
                            ->whereColumn('handover_packages.acceptance_scope_id', 'acceptance_scopes.id')
                            ->where('handover_package_documents.is_required', true)
                            ->where('handover_package_documents.status', '!=', 'approved');
                    });
            })
            ->limit($this->limit())
            ->get([
                'acceptance_scopes.id',
                'acceptance_scopes.project_id',
                'acceptance_scopes.title',
                'acceptance_scopes.status',
                'acceptance_scopes.planned_acceptance_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'acceptance_scope:' . $row->id . ':blocked',
                type: 'handover_blocked_locations',
                priority: $row->status === 'accepted' ? 'warning' : 'critical',
                title: 'Сдача зоны заблокирована',
                text: 'Зона "' . $row->title . '" не готова к передаче заказчику.',
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('acceptance_scope', (int) $row->id, 'Приемка #' . $row->id, '/handover-acceptance'),
                source: $this->key(),
                category: 'handover',
                status: $row->status,
                nextAction: 'Закрыть punch-list и подтвердить обязательные документы комплекта передачи.',
                primaryAction: $this->action('Открыть приемку', '/handover-acceptance', 'handover-acceptance.view'),
                deadline: $row->planned_acceptance_date !== null ? (string) $row->planned_acceptance_date : null,
                ageDays: $this->ageDays($context, $row->planned_acceptance_date),
            ));
    }

    private function entity(string $type, int $id, string $label, string $route): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'route' => $route,
        ];
    }

    private function action(string $label, string $route, string $permission): array
    {
        return [
            'label' => $label,
            'route' => $route,
            'permission' => $permission,
        ];
    }

    private function lookaheadRoute(int $projectId, int $scheduleId): string
    {
        return $scheduleId > 0 ? '/projects/' . $projectId . '/schedules/' . $scheduleId . '/lookahead' : '/schedules';
    }
}
