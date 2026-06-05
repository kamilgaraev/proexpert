<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Closure;
use Database\Seeders\BrickHouseDemoSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BrickHouseDemoScenarioService
{
    public const SCENARIO_SLUG = 'brick-house';
    public const PROJECT_EXTERNAL_CODE = 'BRICK-HOUSE-DEMO-2026';

    private const ORGANIZATION_TAX_NUMBERS = ['7701000001', '7701000002'];

    private const USER_EMAILS = [
        'demo.general-contractor@prohelper.test',
        'demo.contractor@prohelper.test',
        'demo.gp.project-manager@prohelper.test',
        'demo.gp.pto@prohelper.test',
        'demo.gp.foreman@prohelper.test',
        'demo.gp.supply@prohelper.test',
        'demo.gp.accountant@prohelper.test',
        'demo.sub.work-manager@prohelper.test',
        'demo.sub.pto@prohelper.test',
        'demo.sub.foreman@prohelper.test',
        'demo.sub.storekeeper@prohelper.test',
        'demo.sub.accountant@prohelper.test',
    ];

    public function seed(): array
    {
        Artisan::call('db:seed', [
            '--class' => BrickHouseDemoSeeder::class,
            '--force' => true,
        ]);

        return [
            'output' => trim(Artisan::output()),
        ];
    }

    public function delete(bool $dryRun = false): array
    {
        $delete = function () use ($dryRun): array {
            $ids = $this->collectIds();
            $counts = [];

            foreach ($this->deletePlan($ids) as $operation) {
                $count = $operation['run']($dryRun);
                if ($count > 0) {
                    $counts[] = [
                        'table' => $operation['table'],
                        'deleted' => $count,
                    ];
                }
            }

            return [
                'dry_run' => $dryRun,
                'ids' => [
                    'projects' => count($ids['project_ids']),
                    'organizations' => count($ids['organization_ids']),
                    'users' => count($ids['user_ids']),
                ],
                'counts' => $counts,
            ];
        };

        if ($dryRun) {
            return $delete();
        }

        return DB::transaction($delete);
    }

    public function verify(): array
    {
        $projectId = $this->projectId();
        $organizationIds = $this->organizationIds();
        $userIds = $this->userIds();

        $checks = [
            $this->check('Проект brick-house найден', $projectId !== null, $projectId !== null ? 1 : 0, 1),
            $this->check('Две демо-организации найдены', count($organizationIds) === 2, count($organizationIds), 2),
            $this->check('Демо-пользователи найдены', count($userIds) >= 12, count($userIds), 12),
            $this->checkCount('project_organization', 'Связи проекта с организациями', 2, $projectId, $organizationIds),
            $this->checkCount('project_user', 'Доступы пользователей к проекту', 12, $projectId, $organizationIds, $userIds),
            $this->checkCount('contracts', 'Договоры проекта', 2, $projectId, $organizationIds),
            $this->checkCount('estimates', 'Сметы проекта', 2, $projectId, $organizationIds),
            $this->checkCount('project_schedules', 'Графики проекта', 2, $projectId, $organizationIds),
            $this->checkCount('schedule_tasks', 'Задачи графиков', 40, $projectId, $organizationIds),
            $this->checkCount('warehouse_movements', 'Движения склада', 20, $projectId, $organizationIds),
            $this->checkCount('project_material_deliveries', 'Доставки материалов на объект', 8, $projectId, $organizationIds),
            $this->checkCount('construction_journals', 'Журналы работ', 2, $projectId, $organizationIds),
            $this->checkCount('site_requests', 'Заявки с объекта', 18, $projectId, $organizationIds),
            $this->checkCount('completed_works', 'Выполненные работы из журналов', 12, $projectId, $organizationIds),
            $this->checkCount('contract_performance_acts', 'Акты выполненных работ', 2, $projectId, $organizationIds),
            $this->checkCount('payment_documents', 'Платежные документы', 10, $projectId, $organizationIds),
            $this->checkCount('suppliers', 'Поставщики закупок', 10, null, $organizationIds),
            $this->checkCount('purchase_requests', 'Заявки на закупку', 10, $projectId, $organizationIds),
            $this->checkCount('supplier_requests', 'Запросы поставщикам', 10, $projectId, $organizationIds),
            $this->checkCount('supplier_proposals', 'Коммерческие предложения поставщиков', 10, $projectId, $organizationIds),
            $this->checkCount('purchase_orders', 'Заказы поставщикам', 10, $projectId, $organizationIds),
            $this->checkCount('purchase_receipts', 'Приемки по заказам поставщикам', 4, $projectId, $organizationIds),
            $this->checkCount('organization_subscriptions', 'Активные подписки организаций', 2, null, $organizationIds),
            $this->checkCount('organization_custom_roles', 'Кастомные роли организаций', 10, null, $organizationIds),
            $this->checkCount('workforce_employees', 'Сотрудники workforce', 12, null, $organizationIds),
            $this->checkCount('workforce_payroll_statements', 'Ведомости workforce', 2, null, $organizationIds),
            $this->checkCount('safety_work_permits', 'Наряды-допуски безопасности', 4, $projectId, $organizationIds),
            $this->checkCount('safety_incidents', 'Инциденты безопасности', 4, $projectId, $organizationIds),
            $this->checkCount('safety_violations', 'Нарушения безопасности', 4, $projectId, $organizationIds),
            $this->checkCount('safety_briefings', 'Инструктажи безопасности', 4, $projectId, $organizationIds),
            $this->checkCount('quality_defects', 'Замечания качества', 14, $projectId, $organizationIds),
        ];

        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['ok'] === false
        ));

        return [
            'ok' => $failed === [],
            'checks' => $checks,
            'failed' => $failed,
        ];
    }

    private function collectIds(): array
    {
        $projectId = $this->projectId();
        $projectIds = $projectId !== null ? [$projectId] : [];
        $organizationIds = $this->organizationIds();
        $userIds = $this->userIds();
        $contextIds = $this->ids('authorization_contexts', function (Builder $query) use ($projectIds, $organizationIds): void {
            $query->where(function (Builder $scope) use ($projectIds, $organizationIds): void {
                if ($organizationIds !== []) {
                    $scope->orWhere(function (Builder $nested) use ($organizationIds): void {
                        $nested->where('type', 'organization')->whereIn('resource_id', $organizationIds);
                    });
                }

                if ($projectIds !== []) {
                    $scope->orWhere(function (Builder $nested) use ($projectIds): void {
                        $nested->where('type', 'project')->whereIn('resource_id', $projectIds);
                    });
                }
            });
        });

        $contractorIds = $this->idsByScope('contractors', $projectIds, $organizationIds);
        $contractIds = $this->idsByScope('contracts', $projectIds, $organizationIds);
        $estimateIds = $this->idsByScope('estimates', $projectIds, $organizationIds);
        $estimateSectionIds = $this->idsByColumn('estimate_sections', 'estimate_id', $estimateIds);
        $estimateItemIds = $this->idsByColumn('estimate_items', 'estimate_id', $estimateIds);
        $scheduleIds = $this->idsByScope('project_schedules', $projectIds, $organizationIds);
        $scheduleTaskIds = $this->idsByColumn('schedule_tasks', 'schedule_id', $scheduleIds);
        $siteRequestIds = $this->idsByScope('site_requests', $projectIds, $organizationIds);
        $deliveryIds = $this->idsByScope('project_material_deliveries', $projectIds, $organizationIds);
        $journalIds = $this->idsByScope('construction_journals', $projectIds, $organizationIds);
        $journalEntryIds = $this->idsByColumn('construction_journal_entries', 'journal_id', $journalIds);
        $completedWorkIds = $this->idsByScope('completed_works', $projectIds, $organizationIds);
        $actIds = $this->idsByColumn('contract_performance_acts', 'contract_id', $contractIds);
        $paymentDocumentIds = $this->idsByScope('payment_documents', $projectIds, $organizationIds);
        $supplierIds = $this->idsByCodePrefixes('suppliers', $organizationIds, ['BH-GP-SUP-', 'BH-SUB-SUP-']);
        $supplierPartyIds = $this->idsByColumn('supplier_parties', 'registered_supplier_id', $supplierIds);
        $purchaseRequestIds = $this->mergeIds(
            $this->idsByColumn('purchase_requests', 'site_request_id', $siteRequestIds),
            $this->idsByCodePrefixes('purchase_requests', $organizationIds, ['ЗЗ-ГП-ЛД-', 'ЗЗ-ПДР-ЛД-'], 'request_number')
        );
        $supplierRequestIds = $this->mergeIds(
            $this->idsByColumn('supplier_requests', 'purchase_request_id', $purchaseRequestIds),
            $this->idsByCodePrefixes('supplier_requests', $organizationIds, ['ЗП-ГП-ЛД-', 'ЗП-ПДР-ЛД-'], 'request_number')
        );
        $supplierProposalIds = $this->mergeIds(
            $this->idsByColumn('supplier_proposals', 'supplier_request_id', $supplierRequestIds),
            $this->idsByCodePrefixes('supplier_proposals', $organizationIds, ['КП-ГП-', 'КП-ПДР-'], 'proposal_number')
        );
        $purchaseOrderIds = $this->mergeIds(
            $this->idsByColumn('purchase_orders', 'purchase_request_id', $purchaseRequestIds),
            $this->idsByCodePrefixes('purchase_orders', $organizationIds, ['ЗК-ГП-ЛД-', 'ЗК-ПДР-ЛД-'], 'order_number')
        );
        $purchaseReceiptIds = $this->mergeIds(
            $this->idsByColumn('purchase_receipts', 'purchase_order_id', $purchaseOrderIds),
            $this->idsByCodePrefixes('purchase_receipts', $organizationIds, ['ПРМ-ГП-ЛД-', 'ПРМ-ПДР-ЛД-'], 'receipt_number')
        );
        $warehouseIds = $this->ids('organization_warehouses', function (Builder $query) use ($organizationIds): void {
            $query->whereIn('organization_id', $organizationIds)
                ->whereIn('code', ['GP-BRICK-HOUSE', 'SUB-BRICK-HOUSE']);
        });
        $materialIds = $this->idsByCodePrefixes('materials', $organizationIds, ['GP-MAT-', 'SUB-MAT-']);
        $workTypeIds = $this->idsByCodePrefixes('work_types', $organizationIds, ['GP-WORK-', 'SUB-WORK-']);
        $unitIds = $this->idsByColumn('measurement_units', 'organization_id', $organizationIds);
        $qualityDefectIds = $this->idsByScope('quality_defects', $projectIds, $organizationIds);
        $safetyIncidentIds = $this->idsByScope('safety_incidents', $projectIds, $organizationIds);
        $safetyViolationIds = $this->idsByScope('safety_violations', $projectIds, $organizationIds);
        $safetyBriefingIds = $this->idsByScope('safety_briefings', $projectIds, $organizationIds);
        $workforceEmployeeIds = $this->idsByCodePrefixes('workforce_employees', $organizationIds, ['BH-GP-', 'BH-SUB-'], 'personnel_number');
        $workforceScheduleIds = $this->idsByCodePrefixes('workforce_work_schedules', $organizationIds, ['BH-GP-', 'BH-SUB-']);
        $workforcePayrollPeriodIds = $this->idsByScope('workforce_payroll_periods', $projectIds, $organizationIds);
        $workforcePayrollStatementIds = $this->idsByColumn('workforce_payroll_statements', 'payroll_period_id', $workforcePayrollPeriodIds);
        $workforceExportPackageIds = $this->idsByColumn('workforce_export_packages', 'payroll_period_id', $workforcePayrollPeriodIds);
        $workforceQrTokenIds = $this->idsByColumn('workforce_attendance_qr_tokens', 'employee_id', $workforceEmployeeIds);

        return compact(
            'projectIds',
            'organizationIds',
            'userIds',
            'contextIds',
            'contractorIds',
            'contractIds',
            'estimateIds',
            'estimateSectionIds',
            'estimateItemIds',
            'scheduleIds',
            'scheduleTaskIds',
            'siteRequestIds',
            'deliveryIds',
            'journalIds',
            'journalEntryIds',
            'completedWorkIds',
            'actIds',
            'paymentDocumentIds',
            'supplierIds',
            'supplierPartyIds',
            'purchaseRequestIds',
            'supplierRequestIds',
            'supplierProposalIds',
            'purchaseOrderIds',
            'purchaseReceiptIds',
            'warehouseIds',
            'materialIds',
            'workTypeIds',
            'unitIds',
            'qualityDefectIds',
            'safetyIncidentIds',
            'safetyViolationIds',
            'safetyBriefingIds',
            'workforceEmployeeIds',
            'workforceScheduleIds',
            'workforcePayrollPeriodIds',
            'workforcePayrollStatementIds',
            'workforceExportPackageIds',
            'workforceQrTokenIds'
        ) + [
            'project_ids' => $projectIds,
            'organization_ids' => $organizationIds,
            'user_ids' => $userIds,
        ];
    }

    /**
     * @return array<int, array{table: string, run: Closure(bool): int}>
     */
    private function deletePlan(array $ids): array
    {
        return [
            $this->deleteByIds('quality_defect_status_history', 'quality_defect_id', $ids['qualityDefectIds']),
            $this->deleteByIds('quality_defect_photos', 'quality_defect_id', $ids['qualityDefectIds']),
            $this->deleteByIds('safety_briefing_participants', 'briefing_id', $ids['safetyBriefingIds']),
            $this->deleteScoped('safety_corrective_actions', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteScoped('quality_defects', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteScoped('safety_briefings', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteScoped('safety_violations', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteScoped('safety_incidents', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteScoped('safety_work_permits', $ids['projectIds'], $ids['organizationIds']),
            $this->deleteByIds('workforce_export_package_files', 'export_package_id', $ids['workforceExportPackageIds']),
            $this->deleteByIds('workforce_export_packages', 'id', $ids['workforceExportPackageIds']),
            $this->deleteByIds('workforce_payroll_statement_rows', 'payroll_statement_id', $ids['workforcePayrollStatementIds']),
            $this->deleteByIds('workforce_payroll_statements', 'id', $ids['workforcePayrollStatementIds']),
            $this->deleteByIds('workforce_payroll_validation_issues', 'payroll_period_id', $ids['workforcePayrollPeriodIds']),
            $this->deleteByIds('workforce_payroll_source_rows', 'payroll_period_id', $ids['workforcePayrollPeriodIds']),
            $this->deleteByIds('workforce_attendance_scan_events', 'qr_token_id', $ids['workforceQrTokenIds']),
            $this->deleteByIds('workforce_attendance_qr_tokens', 'id', $ids['workforceQrTokenIds']),
            $this->deleteByIds('workforce_attendance_corrections', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByIds('workforce_payroll_periods', 'id', $ids['workforcePayrollPeriodIds']),
            $this->deleteByIds('workforce_orders', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByIds('workforce_business_trips', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByIds('workforce_absences', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByCodes('workforce_absence_types', $ids['organizationIds'], ['SICK_LEAVE']),
            $this->deleteByIds('workforce_work_schedule_days', 'work_schedule_id', $ids['workforceScheduleIds']),
            $this->deleteByIds('workforce_employee_assignments', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByIds('workforce_employment_contracts', 'employee_id', $ids['workforceEmployeeIds']),
            $this->deleteByCodes('workforce_staff_units', $ids['organizationIds'], ['GP-STAFF-', 'SUB-STAFF-'], true),
            $this->deleteByIds('workforce_work_schedules', 'id', $ids['workforceScheduleIds']),
            $this->deleteByCodes('workforce_positions', $ids['organizationIds'], ['BH-GP-', 'BH-SUB-'], true),
            $this->deleteByCodes('workforce_departments', $ids['organizationIds'], ['BH-GP-', 'BH-SUB-'], true),
            $this->deleteByIds('workforce_employees', 'id', $ids['workforceEmployeeIds']),
            $this->deleteByIds('payment_document_site_requests', 'payment_document_id', $ids['paymentDocumentIds']),
            $this->deleteByIds('payment_document_contracts', 'payment_document_id', $ids['paymentDocumentIds']),
            $this->deleteByIds('payment_documents', 'id', $ids['paymentDocumentIds']),
            $this->deleteByIds('purchase_receipt_lines', 'purchase_receipt_id', $ids['purchaseReceiptIds']),
            $this->deleteByIds('purchase_receipts', 'id', $ids['purchaseReceiptIds']),
            $this->deleteByIds('purchase_order_items', 'purchase_order_id', $ids['purchaseOrderIds']),
            $this->deleteByIds('supplier_proposal_decisions', 'supplier_request_id', $ids['supplierRequestIds']),
            $this->deleteByIds('supplier_proposal_intakes', 'supplier_proposal_id', $ids['supplierProposalIds']),
            $this->deleteByIds('supplier_proposal_versions', 'supplier_proposal_id', $ids['supplierProposalIds']),
            $this->deleteByIds('supplier_proposal_lines', 'supplier_proposal_id', $ids['supplierProposalIds']),
            $this->deleteByIds('supplier_proposals', 'id', $ids['supplierProposalIds']),
            $this->deleteByIds('purchase_orders', 'id', $ids['purchaseOrderIds']),
            $this->deleteByIds('supplier_request_versions', 'supplier_request_id', $ids['supplierRequestIds']),
            $this->deleteByIds('supplier_request_lines', 'supplier_request_id', $ids['supplierRequestIds']),
            $this->deleteByIds('supplier_requests', 'id', $ids['supplierRequestIds']),
            $this->deleteByIds('purchase_request_lines', 'purchase_request_id', $ids['purchaseRequestIds']),
            $this->deleteByIds('purchase_requests', 'id', $ids['purchaseRequestIds']),
            $this->deleteByIds('supplier_parties', 'id', $ids['supplierPartyIds']),
            $this->deleteByIds('suppliers', 'id', $ids['supplierIds']),
            $this->deleteByIds('performance_act_completed_works', 'performance_act_id', $ids['actIds']),
            $this->deleteByIds('performance_act_lines', 'performance_act_id', $ids['actIds']),
            $this->deleteByIds('contract_performance_acts', 'id', $ids['actIds']),
            $this->deleteByIds('completed_work_materials', 'completed_work_id', $ids['completedWorkIds']),
            $this->deleteByIds('completed_works', 'id', $ids['completedWorkIds']),
            $this->deleteByIds('journal_work_volumes', 'journal_entry_id', $ids['journalEntryIds']),
            $this->deleteByIds('journal_workers', 'journal_entry_id', $ids['journalEntryIds']),
            $this->deleteByIds('journal_equipment', 'journal_entry_id', $ids['journalEntryIds']),
            $this->deleteByIds('journal_materials', 'journal_entry_id', $ids['journalEntryIds']),
            $this->deleteByIds('construction_journal_entries', 'id', $ids['journalEntryIds']),
            $this->deleteByIds('construction_journals', 'id', $ids['journalIds']),
            $this->deleteByIds('project_material_delivery_events', 'project_material_delivery_id', $ids['deliveryIds']),
            $this->deleteByIds('project_material_deliveries', 'id', $ids['deliveryIds']),
            $this->deleteByIds('site_request_calendar_events', 'site_request_id', $ids['siteRequestIds']),
            $this->deleteByIds('site_request_history', 'site_request_id', $ids['siteRequestIds']),
            $this->deleteByIds('site_requests', 'id', $ids['siteRequestIds']),
            $this->deleteByColumn('site_request_status_transitions', 'organization_id', $ids['organizationIds']),
            $this->deleteByColumn('site_request_statuses', 'organization_id', $ids['organizationIds']),
            $this->deleteByIds('asset_reservations', 'warehouse_id', $ids['warehouseIds']),
            $this->deleteByIds('warehouse_project_allocations', 'warehouse_id', $ids['warehouseIds']),
            $this->deleteByIds('warehouse_movements', 'warehouse_id', $ids['warehouseIds']),
            $this->deleteByIds('warehouse_balances', 'warehouse_id', $ids['warehouseIds']),
            $this->deleteByIds('organization_warehouses', 'id', $ids['warehouseIds']),
            $this->deleteByIds('task_milestones', 'task_id', $ids['scheduleTaskIds']),
            $this->deleteByIds('task_resources', 'task_id', $ids['scheduleTaskIds']),
            $this->deleteByIds('task_dependencies', 'predecessor_task_id', $ids['scheduleTaskIds']),
            $this->deleteByIds('task_dependencies', 'successor_task_id', $ids['scheduleTaskIds']),
            $this->deleteByIds('schedule_tasks', 'id', $ids['scheduleTaskIds']),
            $this->deleteByIds('project_schedules', 'id', $ids['scheduleIds']),
            $this->deleteByIds('contract_estimate_items', 'estimate_item_id', $ids['estimateItemIds']),
            $this->deleteByIds('estimate_items', 'id', $ids['estimateItemIds']),
            $this->deleteByIds('estimate_sections', 'id', $ids['estimateSectionIds']),
            $this->deleteByIds('estimates', 'id', $ids['estimateIds']),
            $this->deleteByIds('contracts', 'id', $ids['contractIds']),
            $this->deleteByIds('contractors', 'id', $ids['contractorIds']),
            $this->deleteByIds('materials', 'id', $ids['materialIds']),
            $this->deleteByIds('work_types', 'id', $ids['workTypeIds']),
            $this->deleteByIds('measurement_units', 'id', $ids['unitIds']),
            $this->deleteByColumn('activity_events', 'project_id', $ids['projectIds']),
            $this->deleteByColumn('organization_package_subscriptions', 'organization_id', $ids['organizationIds']),
            $this->deleteByColumn('organization_module_activations', 'organization_id', $ids['organizationIds']),
            $this->deleteByColumn('organization_subscriptions', 'organization_id', $ids['organizationIds']),
            $this->deleteByIds('user_role_assignments', 'context_id', $ids['contextIds']),
            $this->deleteByCodes('organization_custom_roles', $ids['organizationIds'], ['brick_house_'], true, 'slug'),
            $this->deleteByColumn('project_user', 'project_id', $ids['projectIds']),
            $this->deleteByColumn('project_organization', 'project_id', $ids['projectIds']),
            $this->deleteByColumn('organization_user', 'organization_id', $ids['organizationIds']),
            $this->deleteByIds('authorization_contexts', 'id', $ids['contextIds']),
            $this->deleteByIds('projects', 'id', $ids['projectIds']),
            $this->deleteByIds('organizations', 'id', $ids['organizationIds']),
            $this->deleteByIds('users', 'id', $ids['userIds']),
        ];
    }

    private function deleteByIds(string $table, string $column, array $ids): array
    {
        return [
            'table' => $table,
            'run' => function (bool $dryRun) use ($table, $column, $ids): int {
                if ($ids === [] || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                    return 0;
                }

                return $this->deleteQuery($table, static fn (Builder $query): Builder => $query->whereIn($column, $ids), $dryRun);
            },
        ];
    }

    private function deleteByColumn(string $table, string $column, array $values): array
    {
        return $this->deleteByIds($table, $column, $values);
    }

    private function deleteScoped(string $table, array $projectIds, array $organizationIds): array
    {
        return [
            'table' => $table,
            'run' => function (bool $dryRun) use ($table, $projectIds, $organizationIds): int {
                if (!Schema::hasTable($table) || ($projectIds === [] && $organizationIds === [])) {
                    return 0;
                }

                $hasProjectColumn = Schema::hasColumn($table, 'project_id');
                $hasOrganizationColumn = Schema::hasColumn($table, 'organization_id');

                if (($projectIds === [] || !$hasProjectColumn) && ($organizationIds === [] || !$hasOrganizationColumn)) {
                    return 0;
                }

                return $this->deleteQuery($table, function (Builder $query) use ($table, $projectIds, $organizationIds): Builder {
                    return $query->where(function (Builder $scope) use ($table, $projectIds, $organizationIds): void {
                        if ($projectIds !== [] && Schema::hasColumn($table, 'project_id')) {
                            $scope->orWhereIn('project_id', $projectIds);
                        }

                        if ($organizationIds !== [] && Schema::hasColumn($table, 'organization_id')) {
                            $scope->orWhereIn('organization_id', $organizationIds);
                        }
                    });
                }, $dryRun);
            },
        ];
    }

    private function deleteByCodes(string $table, array $organizationIds, array $codes, bool $prefix = false, string $column = 'code'): array
    {
        return [
            'table' => $table,
            'run' => function (bool $dryRun) use ($table, $organizationIds, $codes, $prefix, $column): int {
                if ($organizationIds === [] || $codes === [] || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                    return 0;
                }

                return $this->deleteQuery($table, function (Builder $query) use ($table, $organizationIds, $codes, $prefix, $column): Builder {
                    if (Schema::hasColumn($table, 'organization_id')) {
                        $query->whereIn('organization_id', $organizationIds);
                    }

                    return $query->where(function (Builder $scope) use ($codes, $prefix, $column): void {
                        foreach ($codes as $code) {
                            $prefix
                                ? $scope->orWhere($column, 'like', $code . '%')
                                : $scope->orWhere($column, $code);
                        }
                    });
                }, $dryRun);
            },
        ];
    }

    private function deleteQuery(string $table, Closure $scope, bool $dryRun): int
    {
        $query = $scope(DB::table($table));
        $count = (int) (clone $query)->count();

        if (!$dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    private function ids(string $table, Closure $scope): array
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
            return [];
        }

        $query = DB::table($table);
        $scope($query);

        return $query->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function idsByScope(string $table, array $projectIds, array $organizationIds): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $hasProjectColumn = Schema::hasColumn($table, 'project_id');
        $hasOrganizationColumn = Schema::hasColumn($table, 'organization_id');

        if (($projectIds === [] || !$hasProjectColumn) && ($organizationIds === [] || !$hasOrganizationColumn)) {
            return [];
        }

        return $this->ids($table, function (Builder $query) use ($table, $projectIds, $organizationIds): void {
            $query->where(function (Builder $scope) use ($table, $projectIds, $organizationIds): void {
                if ($projectIds !== [] && Schema::hasColumn($table, 'project_id')) {
                    $scope->orWhereIn('project_id', $projectIds);
                }

                if ($organizationIds !== [] && Schema::hasColumn($table, 'organization_id')) {
                    $scope->orWhereIn('organization_id', $organizationIds);
                }
            });
        });
    }

    private function idsByColumn(string $table, string $column, array $values): array
    {
        if ($values === [] || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return [];
        }

        return $this->ids($table, function (Builder $query) use ($column, $values): void {
            $query->whereIn($column, $values);
        });
    }

    private function mergeIds(array ...$idSets): array
    {
        return array_values(array_unique(array_merge(...$idSets)));
    }

    private function idsByCodePrefixes(string $table, array $organizationIds, array $prefixes, string $column = 'code'): array
    {
        if ($organizationIds === [] || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return [];
        }

        return $this->ids($table, function (Builder $query) use ($table, $organizationIds, $prefixes, $column): void {
            if (Schema::hasColumn($table, 'organization_id')) {
                $query->whereIn('organization_id', $organizationIds);
            }

            $query->where(function (Builder $scope) use ($prefixes, $column): void {
                foreach ($prefixes as $prefix) {
                    $scope->orWhere($column, 'like', $prefix . '%');
                }
            });
        });
    }

    private function projectId(): ?int
    {
        if (!Schema::hasTable('projects') || !Schema::hasColumn('projects', 'external_code')) {
            return null;
        }

        $id = DB::table('projects')
            ->where('external_code', self::PROJECT_EXTERNAL_CODE)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function organizationIds(): array
    {
        if (!Schema::hasTable('organizations') || !Schema::hasColumn('organizations', 'tax_number')) {
            return [];
        }

        return DB::table('organizations')
            ->whereIn('tax_number', self::ORGANIZATION_TAX_NUMBERS)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function userIds(): array
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'email')) {
            return [];
        }

        return DB::table('users')
            ->whereIn('email', self::USER_EMAILS)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function check(string $name, bool $ok, int $actual, int $expected): array
    {
        return compact('name', 'ok', 'actual', 'expected');
    }

    private function checkCount(
        string $table,
        string $name,
        int $expected,
        ?int $projectId = null,
        array $organizationIds = [],
        array $userIds = []
    ): array {
        if (!Schema::hasTable($table)) {
            return $this->check($name, false, 0, $expected);
        }

        $query = DB::table($table);

        if ($projectId !== null && Schema::hasColumn($table, 'project_id')) {
            $query->where('project_id', $projectId);
        }

        if ($organizationIds !== [] && Schema::hasColumn($table, 'organization_id')) {
            $query->whereIn('organization_id', $organizationIds);
        }

        if ($userIds !== [] && Schema::hasColumn($table, 'user_id')) {
            $query->whereIn('user_id', $userIds);
        }

        $actual = (int) $query->count();

        return $this->check($name, $actual >= $expected, $actual, $expected);
    }
}
