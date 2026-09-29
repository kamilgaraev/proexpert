<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;

final class AssistantWorkforceCatalogMetadata
{
    public static function recordDefinitions(): array
    {
        return [
            'workforce_employee' => ['model' => \App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee::class, 'table' => 'workforce_employees', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'user_id', 'personnel_number', 'last_name', 'first_name', 'middle_name', 'employment_status', 'hire_date', 'dismissal_date', 'phone', 'email', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'user_id', 'personnel_number', 'last_name', 'first_name', 'middle_name', 'employment_status', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_department' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceDepartmentRecord::class, 'table' => 'workforce_departments', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'parent_id', 'code', 'name', 'is_active', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'parent_id', 'code', 'name', 'is_active', 'created_at', 'updated_at'], 'parents' => ['parent_id' => ['type' => 'workforce_department', 'nullable' => true, 'reference_only' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_position' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePositionRecord::class, 'table' => 'workforce_positions', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'code', 'name', 'category', 'is_active', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'code', 'name', 'category', 'is_active', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_work_schedule' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceWorkScheduleRecord::class, 'table' => 'workforce_work_schedules', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'code', 'name', 'schedule_type', 'hours_per_day', 'is_active', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'code', 'name', 'schedule_type', 'hours_per_day', 'is_active', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_staff_unit' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceStaffUnitRecord::class, 'table' => 'workforce_staff_units', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'department_id', 'position_id', 'code', 'headcount', 'rate', 'base_salary', 'valid_from', 'valid_to', 'is_active', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'department_id', 'position_id', 'code', 'headcount', 'valid_from', 'valid_to', 'is_active', 'created_at', 'updated_at'], 'parents' => ['department_id' => ['type' => 'workforce_department', 'nullable' => false], 'position_id' => ['type' => 'workforce_position', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_employment_contract' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceEmploymentContractRecord::class, 'table' => 'workforce_employment_contracts', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'employee_id', 'contract_number', 'contract_date', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'contract_number', 'contract_date', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_employee_assignment' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceEmployeeAssignmentRecord::class, 'table' => 'workforce_employee_assignments', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'employee_id', 'staff_unit_id', 'department_id', 'position_id', 'project_id', 'work_schedule_id', 'rate', 'valid_from', 'valid_to', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'staff_unit_id', 'department_id', 'position_id', 'project_id', 'work_schedule_id', 'valid_from', 'valid_to', 'status', 'created_at', 'updated_at'], 'parents' => ['department_id' => ['type' => 'workforce_department', 'nullable' => false], 'position_id' => ['type' => 'workforce_position', 'nullable' => false], 'staff_unit_id' => ['type' => 'workforce_staff_unit', 'nullable' => false], 'employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'work_schedule_id' => ['type' => 'workforce_work_schedule', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_work_schedule_day' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceWorkScheduleDayRecord::class, 'table' => 'workforce_work_schedule_days', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'work_schedule_id', 'work_date', 'day_type', 'planned_hours', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'work_schedule_id', 'work_date', 'day_type', 'planned_hours', 'created_at', 'updated_at'], 'parents' => ['work_schedule_id' => ['type' => 'workforce_work_schedule', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_absence_type' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceAbsenceTypeRecord::class, 'table' => 'workforce_absence_types', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'code', 'name', 'affects_payroll', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'code', 'name', 'affects_payroll', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_absence' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceAbsenceRecord::class, 'table' => 'workforce_absences', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'employee_id', 'absence_type_id', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'absence_type_id', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_business_trip' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceBusinessTripRecord::class, 'table' => 'workforce_business_trips', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_order' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceOrderRecord::class, 'table' => 'workforce_orders', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'employee_id', 'order_number', 'order_date', 'order_type', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'order_number', 'order_date', 'order_type', 'status', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_period' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollPeriodRecord::class, 'table' => 'workforce_payroll_periods', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'project_id', 'period_start', 'period_end', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'project_id', 'period_start', 'period_end', 'status', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_source_row' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollSourceRowRecord::class, 'table' => 'workforce_payroll_source_rows', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'employee_id', 'project_id', 'work_order_id', 'work_order_line_id', 'timesheet_entry_id', 'work_date', 'source_type', 'hours', 'amount', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'employee_id', 'project_id', 'work_order_id', 'work_order_line_id', 'timesheet_entry_id', 'work_date', 'source_type', 'hours', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_validation_issue' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollValidationIssueRecord::class, 'table' => 'workforce_payroll_validation_issues', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'severity', 'issue_code', 'entity_type', 'entity_id', 'employee_id', 'project_id', 'resolved_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'severity', 'issue_code', 'entity_type', 'entity_id', 'employee_id', 'project_id', 'resolved_at', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_accounting_mapping' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceAccountingMappingRecord::class, 'table' => 'workforce_accounting_mappings', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'scope_type', 'cost_category_id', 'accounting_account', 'priority', 'is_active', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'scope_type', 'cost_category_id', 'accounting_account', 'priority', 'is_active', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_export_package' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceExportPackageRecord::class, 'table' => 'workforce_export_packages', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'supersedes_package_id', 'package_number', 'status', 'sent_at', 'accepted_at', 'rejected_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'supersedes_package_id', 'package_number', 'status', 'sent_at', 'accepted_at', 'rejected_at', 'created_at', 'updated_at'], 'parents' => ['payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false], 'supersedes_package_id' => ['type' => 'workforce_export_package', 'nullable' => true, 'reference_only' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_export_package_file' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceExportPackageFileRecord::class, 'table' => 'workforce_export_package_files', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'export_package_id', 'file_type', 'size_bytes', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'export_package_id', 'file_type', 'size_bytes', 'created_at', 'updated_at'], 'parents' => ['export_package_id' => ['type' => 'workforce_export_package', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_attendance_correction' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceAttendanceCorrectionRecord::class, 'table' => 'workforce_attendance_corrections', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'work_date', 'status', 'hours', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'work_date', 'status', 'hours', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_statement' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollStatementRecord::class, 'table' => 'workforce_payroll_statements', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'statement_number', 'status', 'total_hours', 'gross_amount', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'statement_number', 'status', 'total_hours', 'created_at', 'updated_at'], 'parents' => ['payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_statement_row' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollStatementRowRecord::class, 'table' => 'workforce_payroll_statement_rows', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'payroll_statement_id', 'payroll_period_id', 'employee_id', 'project_id', 'hours', 'gross_amount', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_statement_id', 'payroll_period_id', 'employee_id', 'project_id', 'hours', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false], 'payroll_statement_id' => ['type' => 'workforce_payroll_statement', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_attendance_scan_event' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceAttendanceScanEventRecord::class, 'table' => 'workforce_attendance_scan_events', 'source' => 'workforce_hr', 'domain' => 'workforce_hr', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.hr.manage', 'workforce.employees.basic', 'workforce.audit.view'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'work_date', 'result', 'result_label', 'scanned_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'employee_id', 'project_id', 'work_date', 'result', 'result_label', 'scanned_at', 'created_at', 'updated_at'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true]], 'version_columns' => ['updated_at', 'scanned_at', 'created_at']],
            'workforce_payroll_calculation_version' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollCalculationVersionRecord::class, 'table' => 'workforce_payroll_calculation_versions', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'version', 'status', 'formula_version', 'source_row_count', 'blocking_count', 'warning_count', 'validated_at', 'locked_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'version', 'status', 'formula_version', 'blocking_count', 'warning_count', 'validated_at', 'locked_at', 'created_at', 'updated_at'], 'parents' => ['payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'workforce_payroll_calculation_transition' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollCalculationTransitionRecord::class, 'table' => 'workforce_payroll_calculation_transitions', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'calculation_version_id', 'status', 'transitioned_at', 'transition_hash'], 'rag_fields' => ['id', 'organization_id', 'calculation_version_id', 'status', 'transitioned_at', 'transition_hash'], 'parents' => ['calculation_version_id' => ['type' => 'workforce_payroll_calculation_version', 'nullable' => false]], 'version_columns' => ['transitioned_at']],
            'workforce_payroll_calculation_source_row' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollCalculationSourceRowRecord::class, 'table' => 'workforce_payroll_calculation_source_rows', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'calculation_version_id', 'source_row_id', 'employee_id', 'project_id', 'employee_name', 'project_name', 'work_date', 'source_type', 'hours', 'rate_version_id', 'rate_type', 'rate', 'amount', 'currency'], 'rag_fields' => ['id', 'organization_id', 'calculation_version_id', 'source_row_id', 'employee_id', 'project_id', 'employee_name', 'project_name', 'work_date', 'source_type', 'hours', 'rate_version_id', 'rate_type'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'calculation_version_id' => ['type' => 'workforce_payroll_calculation_version', 'nullable' => false]], 'version_columns' => []],
            'workforce_payroll_calculation_issue' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforcePayrollCalculationIssueRecord::class, 'table' => 'workforce_payroll_calculation_issues', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.employees.basic'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'calculation_version_id', 'source_issue_id', 'source_row_id', 'severity', 'issue_code', 'employee_id', 'project_id', 'employee_name', 'project_name'], 'rag_fields' => ['id', 'organization_id', 'calculation_version_id', 'source_issue_id', 'source_row_id', 'severity', 'issue_code', 'employee_id', 'project_id', 'employee_name', 'project_name'], 'parents' => ['employee_id' => ['type' => 'workforce_employee', 'nullable' => true], 'calculation_version_id' => ['type' => 'workforce_payroll_calculation_version', 'nullable' => false]], 'version_columns' => []],
            'workforce_payroll_readiness_snapshot' => ['model' => \App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotRecord::class, 'table' => 'workforce_payroll_readiness_snapshots', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'project_id', 'period_start', 'period_end', 'snapshot_kind', 'result_code', 'reason_code', 'evaluated_at', 'schema_version', 'formula_version', 'policy_version', 'policy_hash', 'blocker_codes', 'gap_codes', 'source_row_count', 'validation_issue_count', 'blocker_count', 'item_count', 'created_at', 'sealed_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'project_id', 'period_start', 'period_end', 'snapshot_kind', 'result_code', 'reason_code', 'evaluated_at', 'schema_version', 'formula_version', 'policy_version', 'policy_hash', 'blocker_codes', 'gap_codes', 'created_at', 'sealed_at'], 'parents' => ['payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false]], 'version_columns' => ['evaluated_at', 'sealed_at', 'created_at']],
            'workforce_payroll_readiness_snapshot_item' => ['model' => \App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotItemRecord::class, 'table' => 'workforce_payroll_readiness_snapshot_items', 'source' => 'workforce_payroll', 'domain' => 'workforce_payroll', 'module' => 'workforce-management', 'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'payroll_period_id', 'payroll_readiness_snapshot_id', 'position', 'source_type', 'source_id', 'evidence_code', 'evidence_status', 'created_at'], 'rag_fields' => ['id', 'organization_id', 'payroll_period_id', 'payroll_readiness_snapshot_id', 'position', 'source_type', 'source_id', 'evidence_code', 'evidence_status', 'created_at'], 'parents' => ['payroll_period_id' => ['type' => 'workforce_payroll_period', 'nullable' => false], 'payroll_readiness_snapshot_id' => ['type' => 'workforce_payroll_readiness_snapshot', 'nullable' => false]], 'version_columns' => ['created_at']],
            'workforce_capacity_snapshot' => ['model' => \App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacitySnapshotRecord::class, 'table' => 'workforce_capacity_snapshots', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'organization_id', 'as_of_date', 'month_start', 'staff_unit_id', 'project_id', 'capture_kind', 'source_schema_version', 'formula_version', 'policy_version', 'policy_hash', 'authorized_fte', 'assigned_fte', 'available_fte', 'approved_unavailability_fte', 'open_fte', 'overallocated_fte', 'scheduled_hours', 'capacity_status', 'gap_codes', 'source_counts', 'item_count', 'captured_at', 'sealed_at'], 'rag_fields' => ['id', 'organization_id', 'as_of_date', 'month_start', 'staff_unit_id', 'project_id', 'capture_kind', 'source_schema_version', 'formula_version', 'policy_version', 'policy_hash', 'authorized_fte', 'assigned_fte', 'available_fte', 'approved_unavailability_fte', 'open_fte', 'overallocated_fte', 'scheduled_hours', 'capacity_status', 'gap_codes', 'source_counts', 'captured_at', 'sealed_at'], 'parents' => ['staff_unit_id' => ['type' => 'workforce_staff_unit', 'nullable' => false]], 'version_columns' => ['captured_at', 'sealed_at']],
            'workforce_capacity_snapshot_item' => ['model' => \App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacitySnapshotItemRecord::class, 'table' => 'workforce_capacity_snapshot_items', 'source' => 'workforce', 'domain' => 'workforce', 'module' => 'workforce-management', 'permissions' => ['workforce.view'], 'organization_column' => 'organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'workforce_capacity_snapshot_id', 'organization_id', 'staff_unit_id', 'project_id', 'month_start', 'position', 'source_type', 'source_id', 'source_revision_hash', 'created_at'], 'rag_fields' => ['id', 'workforce_capacity_snapshot_id', 'organization_id', 'staff_unit_id', 'project_id', 'month_start', 'position', 'source_type', 'source_id', 'source_revision_hash', 'created_at'], 'parents' => ['staff_unit_id' => ['type' => 'workforce_staff_unit', 'nullable' => false], 'workforce_capacity_snapshot_id' => ['type' => 'workforce_capacity_snapshot', 'nullable' => false]], 'version_columns' => ['created_at']],
            'brigade_profile' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile::class, 'table' => 'brigades', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.catalog.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'owner_user_id', 'name', 'slug', 'team_size', 'availability_status', 'verification_status', 'rating', 'completed_projects_count', 'contact_person', 'contact_phone', 'contact_email', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'name', 'slug', 'team_size', 'availability_status', 'verification_status', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_member' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeMember::class, 'table' => 'brigade_members', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.catalog.view'], 'organization_column' => null, 'project_column' => null, 'read_fields' => ['id', 'brigade_id', 'user_id', 'full_name', 'role', 'is_manager', 'is_active', 'phone', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'brigade_id', 'user_id', 'full_name', 'role', 'is_manager', 'is_active', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_request' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest::class, 'table' => 'brigade_requests', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.requests.view'], 'organization_column' => 'contractor_organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'contractor_organization_id', 'project_id', 'title', 'specialization_name', 'city', 'team_size_min', 'team_size_max', 'status', 'published_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'contractor_organization_id', 'project_id', 'title', 'specialization_name', 'city', 'team_size_min', 'team_size_max', 'status', 'published_at', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_assignment' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment::class, 'table' => 'brigade_project_assignments', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.assignments.view'], 'organization_column' => 'contractor_organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'brigade_id', 'project_id', 'contractor_organization_id', 'status', 'starts_at', 'ends_at', 'source_type', 'source_id', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'brigade_id', 'project_id', 'contractor_organization_id', 'status', 'starts_at', 'ends_at', 'source_type', 'source_id', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_invitation' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation::class, 'table' => 'brigade_invitations', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.invitations.view'], 'organization_column' => 'contractor_organization_id', 'project_column' => 'project_id', 'read_fields' => ['id', 'brigade_id', 'contractor_organization_id', 'project_id', 'status', 'starts_at', 'expires_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'brigade_id', 'contractor_organization_id', 'project_id', 'status', 'starts_at', 'expires_at', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_response' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse::class, 'table' => 'brigade_responses', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.responses.view'], 'organization_column' => null, 'project_column' => null, 'read_fields' => ['id', 'request_id', 'brigade_id', 'status', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'request_id', 'brigade_id', 'status', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false], 'request_id' => ['type' => 'brigade_request', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_document' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeDocument::class, 'table' => 'brigade_documents', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.catalog.view'], 'organization_column' => null, 'project_column' => null, 'read_fields' => ['id', 'brigade_id', 'title', 'document_type', 'verification_status', 'verified_at', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'brigade_id', 'title', 'document_type', 'verification_status', 'verified_at', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_specialization' => ['model' => \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeSpecialization::class, 'table' => 'brigade_specializations', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.catalog.view'], 'organization_column' => null, 'project_column' => null, 'read_fields' => ['id', 'name', 'slug', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'name', 'slug', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
            'brigade_specialization_link' => ['model' => \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantBrigadeSpecializationLinkRecord::class, 'table' => 'brigade_profile_specialization', 'source' => 'brigades', 'domain' => 'brigades', 'module' => 'brigades', 'permissions' => ['brigades.view', 'brigades.catalog.view'], 'organization_column' => null, 'project_column' => null, 'read_fields' => ['id', 'brigade_id', 'specialization_id', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'brigade_id', 'specialization_id', 'created_at', 'updated_at'], 'parents' => ['brigade_id' => ['type' => 'brigade_profile', 'nullable' => false], 'specialization_id' => ['type' => 'brigade_specialization', 'nullable' => false]], 'version_columns' => ['updated_at', 'created_at']],
            'material' => ['model' => \App\Models\Material::class, 'table' => 'materials', 'source' => 'catalog_materials', 'domain' => 'materials', 'module' => 'catalog-management', 'permissions' => ['materials.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'name', 'code', 'measurement_unit_id', 'category', 'is_active', 'default_price', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'name', 'code', 'measurement_unit_id', 'category', 'is_active', 'created_at', 'updated_at'], 'parents' => ['measurement_unit_id' => ['type' => 'measurement_unit', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'work_type' => ['model' => \App\Models\WorkType::class, 'table' => 'work_types', 'source' => 'catalog_work_types', 'domain' => 'work_types', 'module' => 'catalog-management', 'permissions' => ['work_types.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'name', 'code', 'measurement_unit_id', 'category', 'is_active', 'default_price', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'name', 'code', 'measurement_unit_id', 'category', 'is_active', 'created_at', 'updated_at'], 'parents' => ['measurement_unit_id' => ['type' => 'measurement_unit', 'nullable' => true]], 'version_columns' => ['updated_at', 'created_at']],
            'measurement_unit' => ['model' => \App\Models\MeasurementUnit::class, 'table' => 'measurement_units', 'source' => 'catalog_measurement_units', 'domain' => 'measurement_units', 'module' => 'catalog-management', 'permissions' => ['measurement_units.view'], 'organization_column' => 'organization_id', 'project_column' => null, 'read_fields' => ['id', 'organization_id', 'name', 'short_name', 'type', 'is_default', 'is_system', 'created_at', 'updated_at'], 'rag_fields' => ['id', 'organization_id', 'name', 'short_name', 'type', 'is_default', 'is_system', 'created_at', 'updated_at'], 'parents' => [], 'version_columns' => ['updated_at', 'created_at']],
        ];
    }
    public static function excludedInventory(): array
    {
        return ['workforce_attendance_qr_tokens' => 'credential_token', 'workforce_report_owner_facts' => 'internal_change_ledger', 'workforce_report_owner_fact_eligibility' => 'internal_change_ledger', 'workforce_report_snapshots' => 'derived_reporting_uuid_snapshot', 'workforce_capacity_snapshot_rows' => 'derived_reporting_uuid_snapshot', 'attendance_execution_snapshot_rows' => 'derived_reporting_uuid_snapshot', 'payroll_readiness_snapshot_rows' => 'derived_reporting_uuid_snapshot', 'workforce_payroll_reporting_snapshots' => 'derived_reporting_uuid_snapshot', 'workforce_payroll_reporting_rows' => 'derived_reporting_uuid_snapshot', 'workforce_capacity_capture_requests' => 'internal_capture_queue', 'workforce_capacity_capture_ranges' => 'internal_capture_queue', 'workforce_capacity_frozen_source_rows' => 'internal_frozen_payload'];
    }

    public static function entityDefinitions(): array
    {
        return array_map(static fn (array $record): array => [$record['source'], $record['model'], $record['domain']], self::recordDefinitions());
    }

    public static function domainGates(): array
    {
        return ['workforce' => ['workforce-management', ['workforce.view']],
            'workforce_hr' => ['workforce-management', ['workforce.hr.manage']],
            'workforce_payroll' => ['workforce-management', ['workforce.payroll-source.manage']],
            'brigades' => ['brigades', ['brigades.view']],
            'materials' => ['catalog-management', ['materials.view']],
            'work_types' => ['catalog-management', ['work_types.view']],
            'measurement_units' => ['catalog-management', ['measurement_units.view']]];
    }

    public static function entityPermissions(): array
    {
        return array_map(static fn (array $record): array => $record['permissions'], self::recordDefinitions());
    }

    public static function sourcePermissions(): array
    {
        return ['workforce' => ['workforce.view'], 'workforce_hr' => ['workforce.view', 'workforce.hr.manage'],
            'workforce_payroll' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'],
            'brigades' => ['brigades.view'], 'catalog_materials' => ['materials.view'],
            'catalog_work_types' => ['work_types.view'], 'catalog_measurement_units' => ['measurement_units.view']];
    }

    public static function parentColumns(): array
    {
        return array_map(static fn (array $record): array => $record['parents'], self::recordDefinitions());
    }

    public static function organizationColumns(): array
    {
        return array_map(static fn (array $record): ?string => $record['organization_column'], self::recordDefinitions());
    }

    public static function publicCatalogEntities(): array
    {
        return ['brigade_profile' => ['approval_column' => 'verification_status', 'approved_value' => 'approved']];
    }

    public static function globalCatalogEntities(): array
    {
        return ['brigade_specialization' => true];
    }

    public static function globalFanoutEntities(): array
    {
        return array_fill_keys(['brigade_profile', 'brigade_member', 'brigade_document', 'brigade_specialization_link'], true);
    }

    public static function safeSelectColumns(): array
    {
        return array_map(static fn (array $record): array => array_values(array_unique([
            ...$record['read_fields'], ...$record['version_columns'], ...array_keys($record['parents']),
        ])), self::recordDefinitions());
    }

    public static function observerDefinitions(): array
    {
        $definitions = [];
        foreach (self::recordDefinitions() as $type => $record) {
            $definitions[$record['model']] = [$record['source'], $type];
        }
        return $definitions;
    }

    public static function sourceClasses(): array
    {
        return [\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkforceRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkforceHrRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkforcePayrollRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\BrigadeRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CatalogMaterialsRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CatalogWorkTypesRagSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CatalogUnitsRagSource::class];
    }

    public static function navigationTemplates(): array
    {
        $templates = [];
        foreach (self::recordDefinitions() as $type => $record) {
            $templates[$type] = match ($record['domain']) {
                'materials' => '/catalogs/materials', 'work_types' => '/catalogs/work-types', 'measurement_units' => '/catalogs/measurement-units',
                'brigades' => match ($type) {
                    'brigade_request', 'brigade_response' => '/brigades/requests',
                    'brigade_assignment' => '/brigades/catalog', 'brigade_invitation' => '/brigades/invitations',
                    default => '/brigades/catalog',
                },
                default => '/workforce',
            };
        }
        return $templates;
    }

    public static function organizationAggregates(): array
    {
        return array_fill_keys(['workforce_staff_unit', 'workforce_payroll_period', 'workforce_payroll_statement',
            'workforce_payroll_calculation_version', 'workforce_payroll_readiness_snapshot', 'workforce_capacity_snapshot',
            'workforce_export_package', 'workforce_export_package_file'], true);
    }

    public static function numericFields(): array
    {
        return ['default_price', 'base_salary', 'amount', 'gross_amount', 'rate', 'hours', 'total_hours', 'hours_per_day',
            'planned_hours', 'headcount', 'authorized_fte', 'assigned_fte', 'available_fte', 'approved_unavailability_fte',
            'open_fte', 'overallocated_fte', 'scheduled_hours', 'team_size', 'team_size_min', 'team_size_max', 'rating', 'size_bytes'];
    }

    public static function structuredFields(): array
    {
        return array_values(array_unique(array_merge(...array_column(self::recordDefinitions(), 'read_fields'))));
    }

    public static function factFieldGroups(): array
    {
        return [
            'money' => ['default_price', 'base_salary', 'amount', 'gross_amount'],
            'quantity' => ['rate', 'hours', 'total_hours', 'hours_per_day', 'planned_hours', 'headcount', 'authorized_fte', 'assigned_fte', 'available_fte', 'approved_unavailability_fte', 'open_fte', 'overallocated_fte', 'scheduled_hours', 'team_size', 'team_size_min', 'team_size_max', 'source_row_count', 'blocking_count', 'warning_count', 'item_count', 'blocker_count', 'validation_issue_count', 'completed_projects_count', 'rating', 'size_bytes'],
            'date' => ['hire_date', 'dismissal_date', 'valid_from', 'valid_to', 'work_date', 'start_date', 'end_date', 'period_start', 'period_end', 'as_of_date', 'month_start', 'order_date', 'contract_date', 'starts_at', 'ends_at', 'captured_at', 'evaluated_at', 'sealed_at', 'scanned_at', 'transitioned_at', 'created_at', 'updated_at', 'locked_at', 'validated_at', 'sent_at', 'accepted_at', 'rejected_at', 'expires_at', 'published_at', 'resolved_at', 'verified_at'],
            'status' => ['status', 'employment_status', 'availability_status', 'capacity_status', 'verification_status', 'evidence_status', 'result', 'result_code', 'day_type', 'is_active'],
            'owner' => ['employee_id', 'employee_name', 'full_name', 'last_name', 'first_name', 'middle_name', 'user_id', 'owner_user_id', 'brigade_id', 'department_id', 'position_id', 'contact_person'],
        ];
    }

    public static function entityLabels(): array
    {
        return [
            'workforce_employee' => 'Сотрудник', 'workforce_department' => 'Подразделение', 'workforce_position' => 'Должность',
            'workforce_work_schedule' => 'Рабочий график', 'workforce_staff_unit' => 'Штатная единица', 'workforce_employment_contract' => 'Трудовой договор',
            'workforce_employee_assignment' => 'Назначение сотрудника', 'workforce_work_schedule_day' => 'День рабочего графика',
            'workforce_absence_type' => 'Вид отсутствия', 'workforce_absence' => 'Отсутствие сотрудника', 'workforce_business_trip' => 'Командировка',
            'workforce_order' => 'Кадровый приказ', 'workforce_payroll_period' => 'Расчётный период', 'workforce_payroll_source_row' => 'Строка начисления',
            'workforce_payroll_validation_issue' => 'Проверка начисления', 'workforce_accounting_mapping' => 'Правило бухгалтерского учёта',
            'workforce_export_package' => 'Пакет выгрузки', 'workforce_export_package_file' => 'Файл выгрузки', 'workforce_attendance_correction' => 'Корректировка посещаемости',
            'workforce_payroll_statement' => 'Расчётная ведомость', 'workforce_payroll_statement_row' => 'Строка ведомости', 'workforce_attendance_scan_event' => 'Отметка посещаемости',
            'workforce_payroll_calculation_version' => 'Версия расчёта', 'workforce_payroll_calculation_transition' => 'Изменение состояния расчёта',
            'workforce_payroll_calculation_source_row' => 'Исходная строка расчёта', 'workforce_payroll_calculation_issue' => 'Проверка расчёта',
            'workforce_payroll_readiness_snapshot' => 'Состояние готовности расчёта', 'workforce_payroll_readiness_snapshot_item' => 'Показатель готовности расчёта',
            'workforce_capacity_snapshot' => 'Состояние кадровой обеспеченности', 'workforce_capacity_snapshot_item' => 'Показатель кадровой обеспеченности',
            'brigade_profile' => 'Бригада', 'brigade_member' => 'Участник бригады', 'brigade_specialization' => 'Специализация бригады',
            'brigade_specialization_link' => 'Специализация бригады', 'brigade_request' => 'Заявка на бригаду', 'brigade_response' => 'Отклик бригады',
            'brigade_assignment' => 'Назначение бригады', 'brigade_document' => 'Документ бригады', 'brigade_invitation' => 'Приглашение бригады',
            'material' => 'Материал', 'work_type' => 'Вид работ', 'measurement_unit' => 'Единица измерения',
        ];
    }

    public static function fieldLabels(): array
    {
        return [
            'id' => 'Идентификатор',
            'name' => 'Название',
            'title' => 'Наименование',
            'code' => 'Код',
            'short_name' => 'Краткое название',
            'slug' => 'Код специализации',
            'organization_id' => 'Организация',
            'contractor_organization_id' => 'Организация заказчика',
            'project_id' => 'Проект',
            'project_name' => 'Проект',
            'user_id' => 'Пользователь',
            'owner_user_id' => 'Ответственный',
            'employee_id' => 'Сотрудник',
            'employee_name' => 'Сотрудник',
            'personnel_number' => 'Табельный номер',
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'middle_name' => 'Отчество',
            'full_name' => 'ФИО',
            'phone' => 'Телефон',
            'email' => 'Электронная почта',
            'employment_status' => 'Трудовой статус',
            'hire_date' => 'Дата приёма',
            'dismissal_date' => 'Дата увольнения',
            'parent_id' => 'Родительская запись',
            'department_id' => 'Подразделение',
            'position_id' => 'Должность',
            'position' => 'Должность',
            'staff_unit_id' => 'Штатная единица',
            'work_schedule_id' => 'Рабочий график',
            'schedule_type' => 'Вид графика',
            'hours_per_day' => 'Часы в день',
            'headcount' => 'Численность',
            'rate' => 'Ставка',
            'rate_type' => 'Вид ставки',
            'rate_version_id' => 'Версия ставки',
            'base_salary' => 'Оклад',
            'amount' => 'Сумма',
            'gross_amount' => 'Начислено',
            'currency' => 'Валюта',
            'default_price' => 'Цена',
            'measurement_unit_id' => 'Единица измерения',
            'type' => 'Вид',
            'category' => 'Категория',
            'status' => 'Статус',
            'is_active' => 'Активность',
            'is_default' => 'По умолчанию',
            'is_manager' => 'Руководитель',
            'is_system' => 'Системная запись',
            'valid_from' => 'Действует с',
            'valid_to' => 'Действует до',
            'work_date' => 'Рабочая дата',
            'day_type' => 'Вид дня',
            'hours' => 'Часы',
            'total_hours' => 'Всего часов',
            'planned_hours' => 'Плановые часы',
            'start_date' => 'Дата начала',
            'end_date' => 'Дата окончания',
            'starts_at' => 'Начало',
            'ends_at' => 'Окончание',
            'absence_type_id' => 'Вид отсутствия',
            'affects_payroll' => 'Влияние на начисления',
            'order_number' => 'Номер приказа',
            'order_type' => 'Вид приказа',
            'order_date' => 'Дата приказа',
            'contract_number' => 'Номер договора',
            'contract_date' => 'Дата договора',
            'payroll_period_id' => 'Расчётный период',
            'period_start' => 'Начало периода',
            'period_end' => 'Конец периода',
            'payroll_statement_id' => 'Расчётная ведомость',
            'statement_number' => 'Номер ведомости',
            'source_type' => 'Вид источника',
            'source_id' => 'Исходная запись',
            'source_row_id' => 'Исходная строка',
            'source_issue_id' => 'Исходная проверка',
            'source_row_count' => 'Количество исходных строк',
            'entity_type' => 'Вид записи',
            'entity_id' => 'Связанная запись',
            'issue_code' => 'Код проверки',
            'severity' => 'Важность',
            'priority' => 'Приоритет',
            'scope_type' => 'Область действия',
            'accounting_account' => 'Счёт учёта',
            'cost_category_id' => 'Категория затрат',
            'export_package_id' => 'Пакет выгрузки',
            'package_number' => 'Номер пакета',
            'supersedes_package_id' => 'Предыдущий пакет',
            'file_type' => 'Вид файла',
            'size_bytes' => 'Размер файла в байтах',
            'calculation_version_id' => 'Версия расчёта',
            'version' => 'Версия',
            'formula_version' => 'Версия формулы',
            'schema_version' => 'Версия структуры',
            'source_schema_version' => 'Версия структуры источника',
            'blocking_count' => 'Количество блокирующих проверок',
            'warning_count' => 'Количество предупреждений',
            'blocker_count' => 'Количество препятствий',
            'validation_issue_count' => 'Количество проверок',
            'item_count' => 'Количество показателей',
            'source_revision_hash' => 'Контрольная сумма источника',
            'transition_hash' => 'Контрольная сумма перехода',
            'policy_hash' => 'Контрольная сумма правил',
            'policy_version' => 'Версия правил',
            'captured_at' => 'Дата фиксации',
            'capture_kind' => 'Вид фиксации',
            'evaluated_at' => 'Дата оценки',
            'sealed_at' => 'Дата утверждения',
            'locked_at' => 'Дата закрытия',
            'validated_at' => 'Дата проверки',
            'transitioned_at' => 'Дата изменения состояния',
            'created_at' => 'Дата создания',
            'updated_at' => 'Дата изменения',
            'sent_at' => 'Дата отправки',
            'accepted_at' => 'Дата принятия',
            'rejected_at' => 'Дата отклонения',
            'expires_at' => 'Срок действия',
            'published_at' => 'Дата публикации',
            'resolved_at' => 'Дата решения',
            'verified_at' => 'Дата подтверждения',
            'scanned_at' => 'Время отметки',
            'result' => 'Результат',
            'result_code' => 'Код результата',
            'result_label' => 'Результат',
            'reason_code' => 'Причина',
            'as_of_date' => 'Дата состояния',
            'month_start' => 'Начало месяца',
            'snapshot_kind' => 'Вид состояния',
            'payroll_readiness_snapshot_id' => 'Состояние готовности расчёта',
            'workforce_capacity_snapshot_id' => 'Состояние кадровой обеспеченности',
            'gap_codes' => 'Причины нехватки',
            'blocker_codes' => 'Препятствия',
            'source_counts' => 'Количество источников',
            'authorized_fte' => 'Штатная численность',
            'assigned_fte' => 'Назначенная численность',
            'available_fte' => 'Доступная численность',
            'approved_unavailability_fte' => 'Согласованное отсутствие',
            'open_fte' => 'Свободная численность',
            'overallocated_fte' => 'Превышение численности',
            'scheduled_hours' => 'Часы по графику',
            'capacity_status' => 'Кадровая обеспеченность',
            'availability_status' => 'Доступность',
            'evidence_code' => 'Код подтверждения',
            'evidence_status' => 'Состояние подтверждения',
            'brigade_id' => 'Бригада',
            'specialization_id' => 'Специализация',
            'specialization_name' => 'Специализация',
            'request_id' => 'Заявка',
            'role' => 'Роль',
            'city' => 'Город',
            'contact_person' => 'Контактное лицо',
            'contact_phone' => 'Контактный телефон',
            'contact_email' => 'Контактная почта',
            'team_size' => 'Численность бригады',
            'team_size_min' => 'Минимальная численность бригады',
            'team_size_max' => 'Максимальная численность бригады',
            'rating' => 'Рейтинг',
            'completed_projects_count' => 'Количество завершённых проектов',
            'verification_status' => 'Статус проверки',
            'document_type' => 'Вид документа',
            'work_order_id' => 'Наряд',
            'work_order_line_id' => 'Строка наряда',
            'timesheet_entry_id' => 'Строка табеля',
        ];
    }

    public static function domainDefinitions(): array
    {
        $definitions = [];
        foreach (self::domainGates() as $domain => [$module]) {
            $records = array_filter(self::recordDefinitions(), static fn (array $record): bool => $record['domain'] === $domain);
            $types = array_keys($records);
            $primary = $types[0];
            $fields = array_values(array_unique(array_merge(...array_column($records, 'read_fields'))));
            $entity = ['type' => 'string', 'enum' => $types];
            $fieldList = ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => $fields]];
            $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties,
                'required' => array_keys($properties), 'additionalProperties' => false];
            $schemas = ['search' => $schema(['entity_type' => $entity, 'query' => ['type' => 'string', 'maxLength' => 200],
                'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'fields' => $fieldList]),
                'read' => $schema(['entity_type' => $entity, 'id' => ['type' => 'integer', 'minimum' => 1], 'fields' => $fieldList]),
                'navigation' => $schema(['entity_type' => $entity, 'id' => ['type' => 'integer', 'minimum' => 1]])];
            $permissions = match ($domain) {
                'workforce' => ['workforce.view'], 'workforce_hr' => ['workforce.view', 'workforce.hr.manage'],
                'workforce_payroll' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view'],
                'brigades' => ['brigades.view'], default => [$domain.'.view'],
            };
            $fieldPermissions = [];
            foreach (['base_salary', 'amount', 'gross_amount', ...($domain === 'workforce_payroll' ? ['rate'] : [])] as $field) {
                if (in_array($field, $fields, true)) { $fieldPermissions[$field] = ['workforce.payroll-source.manage', 'finance.view']; }
            }
            if (in_array('default_price', $fields, true)) { $fieldPermissions['default_price'] = 'finance.view'; }
            foreach (['phone', 'email', 'hire_date', 'dismissal_date'] as $field) {
                if (in_array($field, $fields, true)) { $fieldPermissions[$field] = $domain === 'brigades' ? 'brigades.catalog.moderate' : 'workforce.hr.manage'; }
            }
            foreach (['contact_person', 'contact_phone', 'contact_email'] as $field) {
                if (in_array($field, $fields, true)) { $fieldPermissions[$field] = 'brigades.catalog.moderate'; }
            }
            $entityPermissions = array_map(static fn (array $record): array => $record['permissions'], $records);
            $definitions[] = new AssistantDomainDefinition($domain, $module, $primary, $permissions, $fields, $schemas,
                array_keys($schemas), self::navigationTemplates()[$primary], $records[$primary]['source'], $types, $fieldPermissions, $entityPermissions);
        }
        return $definitions;
    }

    public static function fileParents(): array
    {
        return [\App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeDocument::class =>
                ['entity_type' => 'brigade_document', 'path' => 'file_path', 'name' => 'file_name', 'permissions' => ['brigades.view', 'brigades.catalog.view']],
            \App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceExportPackageFileRecord::class =>
                ['entity_type' => 'workforce_export_package_file', 'path' => 'storage_path', 'disk' => 'storage_disk', 'name' => 'file_name',
                    'permissions' => ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.exports.generate']],
            \App\Models\Material::class => ['entity_type' => 'material', 'relation' => 'files', 'permissions' => ['materials.view']]];
    }
}
