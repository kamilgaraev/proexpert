<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantDomainCatalog
{
    private array $definitions = [];

    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->domain] = $definition;
        }
    }

    public function all(): array
    {
        return $this->definitions;
    }

    public function definition(string $domain): ?AssistantDomainDefinition
    {
        return $this->definitions[$domain] ?? null;
    }

    public function supports(string $domain, string $operation): bool
    {
        return in_array($operation, $this->definition($domain)?->operations ?? [], true);
    }

    public function toolSchemas(): array
    {
        return array_map(static fn (AssistantDomainDefinition $definition): array => $definition->schemas, $this->definitions);
    }

    public static function defaults(): array
    {
        $make = static function (string $domain, string $module, string $entity, array $fields, array $permissions, string $navigation, string $source, array $entities = [], array $fieldPermissions = []): AssistantDomainDefinition {
            $entities = array_values(array_unique([$entity, ...$entities]));
            $id = ['type' => 'integer', 'minimum' => 1];
            $fieldList = ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => $fields]];
            $type = ['type' => 'string', 'enum' => $entities];
            $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
            $schemas = [
                'search' => $schema(['entity_type' => $type, 'query' => ['type' => 'string', 'maxLength' => 200], 'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'fields' => $fieldList]),
                'read' => $schema(['entity_type' => $type, 'id' => $id, 'fields' => $fieldList]),
                'navigation' => $schema(['entity_type' => $type, 'id' => $id]),
            ];
            $entityPermissions = $domain === 'crm' ? ['crm_deal'=>'crm.deals.view','crm_lead'=>'crm.leads.view','crm_company'=>'crm.companies.view','crm_contact'=>'crm.contacts.view','crm_activity'=>'crm.activities.view'] : [];
            return new AssistantDomainDefinition($domain, $module, $entity, $permissions, $fields, $schemas, array_keys($schemas), $navigation, $source, $entities, $fieldPermissions, $entityPermissions);
        };

        return [...[
            $make('projects', 'project-management', 'project', ['id','name','address','description','status','start_date','end_date','budget_amount'], ['projects.view'], '/projects/{id}', 'project', [], ['budget_amount'=>'finance.view_project_budget']),
            $make('estimates', 'budget-estimates', 'estimate', ['id','number','name','status','version','estimate_date','project_id','contract_id','position_number','estimate_id','estimate_section_id','estimate_item_id','item_type','resource_type','quantity','quantity_total','total_quantity','unit_price','total_amount','total_amount_with_vat'], ['budget-estimates.view'], '/estimates/{id}', 'estimate', ['estimate_section','estimate_item','estimate_item_resource'], ['unit_price'=>'budget-estimates.finance.view','total_amount'=>'budget-estimates.finance.view','total_amount_with_vat'=>'budget-estimates.finance.view']),
            $make('contracts', 'contract-management', 'contract', ['id','number','subject','status','date','start_date','end_date','total_amount','planned_advance_amount','actual_advance_amount','project_id'], ['contracts.view'], '/contracts/{id}', 'contract', [], ['total_amount'=>'finance.view','planned_advance_amount'=>'finance.view','actual_advance_amount'=>'finance.view']),
            $make('design', 'design-management', 'design_package', ['id','project_id','package_id','artifact_id','title','document_title','document_code','artifact_type','stage','project_stage','discipline','status','planned_issue_date','issued_at','due_date','resolved_at','severity','body','response','author_id','assignee_id','version_number','revision','revision_label','source_format','file_format','source_original_name','source_mime_type','source_size_bytes','model_date','is_current'], ['design-management.view'], '/design-management', 'design', ['design_artifact','design_artifact_version','design_review_comment','design_model_set']),
            $make('finance', 'payments', 'payment_document', ['id','project_id','document_number','document_type','status','amount','currency','due_date','paid_at'], ['finance.view'], '/payments', 'payment'),
            $make('procurement', 'procurement', 'purchase_request', ['id','project_id','title','status','number','request_number','order_number','required_date','needed_by','order_date','delivery_date','budget_amount','budget_currency','total_amount','currency','purchase_request_id','supplier_request_id','purchase_order_id'], ['procurement.view'], '/procurement', 'procurement', ['supplier_request','supplier_proposal','supplier_proposal_decision','purchase_order','purchase_receipt','procurement_approval','procurement_audit_event'], ['budget_amount'=>'finance.view','total_amount'=>'finance.view']),
            $make('schedule', 'schedule-management', 'schedule_task', ['id','project_id','name','status','planned_start_date','planned_end_date','progress_percent','quantity','completed_quantity','schedule_id'], ['schedule.view'], '/schedules', 'schedule', ['schedule']),
            $make('warehouse', 'basic-warehouse', 'warehouse_balance', ['id','project_id','warehouse_id','material_id','quantity','asset_id','status','name','code','is_active'], ['warehouse.view'], '/warehouse', 'warehouse', ['warehouse','warehouse_movement','warehouse_project_allocation','asset_reservation','inventory_act','warehouse_storage_cell','warehouse_task','warehouse_asset','project_material_delivery']),
            $make('site_requests', 'site-requests', 'site_request', ['id','project_id','title','description','notes','status','priority','request_type','required_date','user_id','assigned_to','material_id','material_name','material_quantity','material_unit','personnel_count','equipment_count','work_start_date','work_end_date','rental_start_date','rental_end_date','equipment_start_at','equipment_end_at'], ['site_requests.view'], '/site-requests', 'site_request'),
            $make('works', 'project-management', 'completed_work', ['id','project_id','contract_id','work_type_id','quantity','completion_date','status','description'], ['contracts.completed_works.view'], '/completed-works/{id}', 'work_completion'),
            $make('acts', 'contract-management', 'performance_act', ['id','project_id','contract_id','number','date','status','total_amount'], ['contracts.performance_acts.view'], '/acts/{id}', 'performance_act', [], ['total_amount'=>'finance.view']),
            $make('people', 'users', 'user', ['id','name','email'], ['users.view'], '/users/{id}', 'people'),
            $make('production_labor', 'production-labor', 'production_labor_work_order', ['id','project_id','title','status','order_number','work_order_id','work_date','hours','quantity','planned_start_date','planned_finish_date'], ['production-labor.view'], '/production-labor', 'production_labor', ['production_labor_work_order_line','production_labor_timesheet','production_labor_timesheet_entry','production_labor_output_entry','production_labor_payroll_accrual']),
            $make('time_tracking', 'time-tracking', 'time_entry', ['id','project_id','worker_name','work_date','hours_worked','volume_completed','title','status','is_billable'], ['time_tracking.view'], '/time-tracking/entries', 'time_tracking'),
            $make('machinery', 'machinery-operations', 'machinery_asset', ['id','project_id','asset_code','name','inventory_number','status','machinery_asset_id','work_date'], ['machinery-operations.view'], '/machinery-operations', 'machinery', ['machinery_assignment','machinery_shift_report','machinery_downtime','machinery_maintenance_order','machinery_fuel_issue','machinery_production_record']),
            $make('quality', 'quality-control', 'quality_defect', ['id','project_id','title','description','status','severity','due_date'], ['quality-control.view'], '/quality-control', 'quality_executive_docs'),
            $make('documents', 'executive-documentation', 'executive_document', ['id','project_id','document_set_id','title','name','number','status','document_type'], ['executive-documentation.view'], '/executive-documentation', 'quality_executive_docs', ['executive_document_set']),
            $make('safety', 'safety-management', 'safety_incident', ['id','project_id','title','description','status','severity','incident_number','incident_type','due_date'], ['safety-management.view'], '/safety-management', 'safety', ['safety_violation','safety_work_permit','safety_briefing','safety_corrective_action','safety_inspection','safety_inspection_finding']),
            $make('change_management', 'change-management', 'change_request', ['id','project_id','title','description','status','request_number','due_date','rfi_number','subject','question','answer','addressee_type','response_due_date','sent_at','answered_at'], ['change-management.view'], '/change-management', 'change_management', ['change_management_rfi','change_impact','change_approval','variation_order','change_claim']),
            $make('handover_acceptance', 'handover-acceptance', 'acceptance_scope', ['id','project_id','title','name','status','description','scope_id','session_id'], ['handover-acceptance.view'], '/handover-acceptance', 'handover_acceptance', ['acceptance_checklist','acceptance_checklist_item','acceptance_finding','acceptance_session','acceptance_signoff','handover_package','handover_package_document','project_location']),
            $make('crm', 'crm', 'crm_deal', ['id','project_id','contract_id','title','name','status','stage_code','pipeline_code','company_id','primary_contact_id','owner_user_id','expected_close_at'], ['crm.view'], '/crm', 'crm', ['crm_lead','crm_company','crm_contact','crm_activity','customer_issue']),
            $make('commercial_processes', 'commercial-proposals', 'commercial_proposal', ['id','number','title','status','project_id','contract_id','currency','valid_until','sent_at'], ['commercial_proposals.view'], '/commercial-proposals/{id}', 'commercial_processes'),
            $make('knowledge', '', 'knowledge_article', ['id','title','slug','excerpt','content_plain_text','tags','status','published_at','reading_time'], [], '/knowledge-hub/articles/{slug}', 'knowledge'),
        ], ...AssistantExtendedDomainRegistry::values('domainDefinitions')];
    }
}
