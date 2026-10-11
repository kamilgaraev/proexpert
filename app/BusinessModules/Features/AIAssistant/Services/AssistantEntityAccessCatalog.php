<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\Project;
use App\Models\User;

final class AssistantEntityAccessCatalog
{
    private static ?array $referencedParentColumns = null;

    private const DOMAINS = [
        'assistant' => ['ai-assistant', ['ai_assistant.chat']],
        'projects' => ['project-management', ['projects.view']],
        'contracts' => ['contract-management', ['contracts.view']],
        'estimates' => ['budget-estimates', ['budget-estimates.view']],
        'finance' => ['payments', ['finance.view', 'finance.view_project_budget', 'payments.dashboard.view', 'payments.invoice.view', 'payments.invoice.view_all']],
        'warehouse' => ['basic-warehouse', ['warehouse.view']],
        'materials' => ['catalog-management', ['materials.view']],
        'people' => ['users', ['users.view']],
        'procurement' => ['procurement', ['procurement.view', 'procurement.purchase_requests.view']],
        'schedule' => ['schedule-management', ['schedule.view']],
        'documents' => ['executive-documentation', ['executive-documentation.view']],
        'quality' => ['quality-control', ['quality-control.view']],
        'safety' => ['safety-management', ['safety-management.view']],
        'change_management' => ['change-management', ['change-management.view']],
        'handover_acceptance' => ['handover-acceptance', ['handover-acceptance.view']],
        'machinery' => ['machinery-operations', ['machinery-operations.view']],
        'production_labor' => ['production-labor', ['production-labor.view']],
        'site_requests' => ['site-requests', ['site_requests.view', 'site-requests.view']],
        'time_tracking' => ['time-tracking', ['time_tracking.view']],
        'crm' => ['crm', ['crm.view']],
        'commercial_processes' => ['commercial-proposals', ['commercial_proposals.view']],
        'reports' => ['reports', ['reports.view']],
        'contractors' => ['catalog-management', ['contractors.view']],
        'measurement_units' => ['catalog-management', ['measurement_units.view']],
        'knowledge' => ['knowledge-hub', ['system_admin.knowledge_hub.articles.view']],
        'design' => ['design-management', ['design-management.view']],
    ];
    private const PARENTS = [
        'estimate_item_resource' => ['item', 'estimate_item'], 'estimate_item' => ['estimate', 'estimate'],
        'estimate_section' => ['estimate', 'estimate'], 'schedule_task' => ['schedule', 'schedule'],
        'construction_journal_entry' => ['journal', 'construction_journal'],
        'crm_activity' => ['deal', 'crm_deal'],
        'performance_act' => ['contract', 'contract'], 'performance_act_line' => ['performanceAct', 'performance_act'],
    ];
    private const SECURITY_PARENT_COLUMNS = [
        'inventory_act' => ['warehouse_id' => ['type' => 'warehouse', 'nullable' => false]],
        'warehouse_storage_cell' => ['warehouse_id' => ['type' => 'warehouse', 'nullable' => false],
            'zone_id' => ['type' => 'warehouse_zone', 'nullable' => true, 'matches' => ['warehouse_id' => 'warehouse_id']]],
    ];

    public static function domainGates(): array
    {
        return AssistantExtendedDomainRegistry::values('domainGates') + self::DOMAINS;
    }

    public static function intrinsicParents(): array
    {
        return self::PARENTS;
    }

    public static function parentColumns(): array
    {
        return AssistantExtendedDomainRegistry::values('parentColumns') + self::SECURITY_PARENT_COLUMNS;
    }

    public static function referencedParentColumns(string $type): array
    {
        if (self::$referencedParentColumns === null) {
            self::$referencedParentColumns = [];
            foreach (self::parentColumns() as $parents) {
                foreach ($parents as $parent) {
                    $parentType = $parent['type'];
                    self::$referencedParentColumns[$parentType][] = $parent['key'] ?? 'id';
                    array_push(self::$referencedParentColumns[$parentType], ...array_keys($parent['matches'] ?? []));
                    if ($parent['match_project'] ?? false) { self::$referencedParentColumns[$parentType][] = 'project_id'; }
                }
            }
        }

        return self::$referencedParentColumns[$type] ?? [];
    }

    public static function entityDefinitions(): array
    {
        return AssistantExtendedDomainRegistry::values('entityDefinitions') + [
            'project' => ['project', Project::class, 'projects'],
            'contract' => ['contract', \App\Models\Contract::class, 'contracts'],
            'estimate' => ['estimate', \App\Models\Estimate::class, 'estimates'],
            'estimate_section' => ['estimate', \App\Models\EstimateSection::class, 'estimates'],
            'estimate_item' => ['estimate', \App\Models\EstimateItem::class, 'estimates'],
            'estimate_item_resource' => ['estimate', \App\Models\EstimateItemResource::class, 'estimates'],
            'estimate_template' => ['estimate_reference', \App\Models\EstimateTemplate::class, 'estimates'],
            'estimate_library_item' => ['estimate_reference', \App\Models\EstimateLibraryItem::class, 'estimates'],
            'normative_rate' => ['estimate_reference', \App\Models\NormativeRate::class, 'estimates'],
            'estimate_catalog_item' => ['estimate_reference', \App\Models\EstimatePositionCatalog::class, 'estimates'],
            'estimate_generation_learning_example' => ['estimate_generation_learning', \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample::class, 'estimates'],
            'payment_document' => ['payment', \App\BusinessModules\Core\Payments\Models\PaymentDocument::class, 'finance'],
            'performance_act' => ['performance_act', \App\Models\ContractPerformanceAct::class, 'contracts'],
            'performance_act_line' => ['performance_act', \App\Models\PerformanceActLine::class, 'contracts'],
            'completed_work' => ['work_completion', \App\Models\CompletedWork::class, 'projects'],
            'design_package' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignPackage::class, 'design'],
            'design_artifact' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifact::class, 'design'],
            'design_artifact_version' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion::class, 'design'],
            'design_review_comment' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment::class, 'design'],
            'design_model_set' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignModelSet::class, 'design'],
            'schedule' => ['schedule', \App\Models\ProjectSchedule::class, 'schedule'],
            'schedule_task' => ['schedule', \App\Models\ScheduleTask::class, 'schedule'],
            'construction_journal' => ['construction_journal', \App\Models\ConstructionJournal::class, 'projects'],
            'construction_journal_entry' => ['construction_journal', \App\Models\ConstructionJournalEntry::class, 'projects'],
            'project_pulse_report' => ['project_pulse', \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::class, 'reports'],
            'material' => ['warehouse', \App\Models\Material::class, 'materials'],
            'change_approval' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval::class, 'change_management'],
            'change_claim' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim::class, 'change_management'],
            'change_impact' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact::class, 'change_management'],
            'change_management_rfi' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::class, 'change_management'],
            'change_request' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest::class, 'change_management'],
            'variation_order' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\VariationOrder::class, 'change_management'],
            'acceptance_checklist' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist::class, 'handover_acceptance'],
            'acceptance_checklist_item' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem::class, 'handover_acceptance'],
            'acceptance_finding' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding::class, 'handover_acceptance'],
            'acceptance_scope' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::class, 'handover_acceptance'],
            'acceptance_session' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession::class, 'handover_acceptance'],
            'acceptance_signoff' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff::class, 'handover_acceptance'],
            'handover_package' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage::class, 'handover_acceptance'],
            'handover_package_document' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument::class, 'handover_acceptance'],
            'project_location' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation::class, 'handover_acceptance'],
            'warehouse_asset' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\Asset::class, 'warehouse'],
            'asset_reservation' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::class, 'warehouse'],
            'inventory_act' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct::class, 'warehouse'],
            'project_material_delivery' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::class, 'warehouse'],
            'warehouse_balance' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::class, 'warehouse'],
            'warehouse_movement' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::class, 'warehouse'],
            'warehouse_project_allocation' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation::class, 'warehouse'],
            'warehouse_storage_cell' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell::class, 'warehouse'],
            'warehouse_task' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask::class, 'warehouse'],
            'site_request' => ['site_request', \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::class, 'site_requests'],
            'machinery_asset' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset::class, 'machinery'],
            'machinery_assignment' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment::class, 'machinery'],
            'machinery_downtime' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime::class, 'machinery'],
            'machinery_fuel_issue' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue::class, 'machinery'],
            'machinery_maintenance_order' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder::class, 'machinery'],
            'machinery_production_record' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord::class, 'machinery'],
            'machinery_shift_report' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport::class, 'machinery'],
            'procurement_approval' => ['procurement', \App\BusinessModules\Features\Procurement\Models\ProcurementApproval::class, 'procurement'],
            'procurement_audit_event' => ['procurement', \App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent::class, 'procurement'],
            'purchase_order' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::class, 'procurement_business'],
            'purchase_request' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::class, 'procurement'],
            'purchase_receipt' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseReceipt::class, 'procurement'],
            'supplier_proposal' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierProposal::class, 'procurement'],
            'supplier_proposal_decision' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision::class, 'procurement'],
            'supplier_request' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierRequest::class, 'procurement'],
            'safety_briefing' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing::class, 'safety'],
            'safety_corrective_action' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction::class, 'safety'],
            'safety_inspection' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection::class, 'safety'],
            'safety_inspection_finding' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding::class, 'safety'],
            'safety_incident' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident::class, 'safety'],
            'safety_violation' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation::class, 'safety'],
            'safety_work_permit' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit::class, 'safety'],
            'executive_document' => ['quality_executive_docs', \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::class, 'documents'],
            'executive_document_set' => ['quality_executive_docs', \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::class, 'documents'],
            'quality_defect' => ['quality_executive_docs', \App\BusinessModules\Features\QualityControl\Models\QualityDefect::class, 'quality'],
            'production_labor_output_entry' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry::class, 'production_labor'],
            'production_labor_payroll_accrual' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual::class, 'production_labor'],
            'production_labor_timesheet' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet::class, 'production_labor'],
            'production_labor_timesheet_entry' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry::class, 'production_labor'],
            'production_labor_work_order' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder::class, 'production_labor'],
            'production_labor_work_order_line' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine::class, 'production_labor'],
            'user' => ['people', User::class, 'people'],
            'warehouse' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::class, 'warehouse'],
            'crm_deal' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmDeal::class, 'crm'],
            'crm_lead' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmLead::class, 'crm'],
            'crm_company' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmCompany::class, 'crm'],
            'crm_contact' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmContact::class, 'crm'],
            'crm_activity' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmActivity::class, 'crm'],
            'customer_issue' => ['crm', \App\Models\CustomerIssue::class, 'crm'],
            'knowledge_article' => ['knowledge', \App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle::class, 'knowledge'],
            'measurement_unit' => ['measurement_units', \App\Models\MeasurementUnit::class, 'measurement_units'],
            'contractor' => ['contract', \App\Models\Contractor::class, 'contractors'],
            'time_entry' => ['time_tracking', \App\Models\TimeEntry::class, 'time_tracking'],
            'commercial_proposal' => ['commercial_processes', \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal::class, 'commercial_processes'],
        ];
    }

}
