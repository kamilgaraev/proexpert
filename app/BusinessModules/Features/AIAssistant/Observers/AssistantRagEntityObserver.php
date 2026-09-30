<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Observers;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class AssistantRagEntityObserver
{
    public static function definitions(): array
    {
        return \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('observerDefinitions') + [
            \App\BusinessModules\Features\DesignManagement\Models\DesignPackage::class => ['design', 'design_package'],
            \App\BusinessModules\Features\DesignManagement\Models\DesignArtifact::class => ['design', 'design_artifact'],
            \App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion::class => ['design', 'design_artifact_version'],
            \App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment::class => ['design', 'design_review_comment'],
            \App\BusinessModules\Features\DesignManagement\Models\DesignModelSet::class => ['design', 'design_model_set'],
            \App\Models\User::class => ['people', 'user'],
            \App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle::class => ['knowledge', 'knowledge_article'],
            \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample::class => ['estimate_generation_learning', 'estimate_generation_learning_example'],
            \App\BusinessModules\Core\Payments\Models\PaymentDocument::class => ['payment', 'payment_document'],
            \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::class => ['project_pulse', 'project_pulse_report'],
            \App\BusinessModules\Features\BasicWarehouse\Models\Asset::class => ['warehouse', 'warehouse_asset'],
            \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::class => ['warehouse', 'asset_reservation'],
            \App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct::class => ['warehouse', 'inventory_act'],
            \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::class => ['warehouse', 'project_material_delivery'],
            \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::class => ['warehouse', 'warehouse_balance'],
            \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::class => ['warehouse', 'warehouse_movement'],
            \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation::class => ['warehouse', 'warehouse_project_allocation'],
            \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell::class => ['warehouse', 'warehouse_storage_cell'],
            \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask::class => ['warehouse', 'warehouse_task'],
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval::class => ['change_management', 'change_approval'],
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim::class => ['change_management', 'change_claim'],
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact::class => ['change_management', 'change_impact'],
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::class => ['change_management', 'change_management_rfi'],
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest::class => ['change_management', 'change_request'],
            \App\BusinessModules\Features\ChangeManagement\Models\VariationOrder::class => ['change_management', 'variation_order'],
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal::class => ['commercial_processes', 'commercial_proposal'],
            \App\BusinessModules\Features\Crm\Models\CrmActivity::class => ['crm', 'crm_activity'],
            \App\BusinessModules\Features\Crm\Models\CrmCompany::class => ['crm', 'crm_company'],
            \App\BusinessModules\Features\Crm\Models\CrmContact::class => ['crm', 'crm_contact'],
            \App\BusinessModules\Features\Crm\Models\CrmDeal::class => ['crm', 'crm_deal'],
            \App\BusinessModules\Features\Crm\Models\CrmLead::class => ['crm', 'crm_lead'],
            \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::class => ['quality_executive_docs', 'executive_document'],
            \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::class => ['quality_executive_docs', 'executive_document_set'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist::class => ['handover_acceptance', 'acceptance_checklist'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem::class => ['handover_acceptance', 'acceptance_checklist_item'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding::class => ['handover_acceptance', 'acceptance_finding'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::class => ['handover_acceptance', 'acceptance_scope'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession::class => ['handover_acceptance', 'acceptance_session'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff::class => ['handover_acceptance', 'acceptance_signoff'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage::class => ['handover_acceptance', 'handover_package'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument::class => ['handover_acceptance', 'handover_package_document'],
            \App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation::class => ['handover_acceptance', 'project_location'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset::class => ['machinery', 'machinery_asset'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment::class => ['machinery', 'machinery_assignment'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime::class => ['machinery', 'machinery_downtime'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue::class => ['machinery', 'machinery_fuel_issue'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder::class => ['machinery', 'machinery_maintenance_order'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord::class => ['machinery', 'machinery_production_record'],
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport::class => ['machinery', 'machinery_shift_report'],
            \App\BusinessModules\Features\Procurement\Models\ProcurementApproval::class => ['procurement', 'procurement_approval'],
            \App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent::class => ['procurement', 'procurement_audit_event'],
            \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::class => ['procurement', 'purchase_order'],
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceipt::class => ['procurement', 'purchase_receipt'],
            \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::class => ['procurement', 'purchase_request'],
            \App\BusinessModules\Features\Procurement\Models\SupplierProposal::class => ['procurement', 'supplier_proposal'],
            \App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision::class => ['procurement', 'supplier_proposal_decision'],
            \App\BusinessModules\Features\Procurement\Models\SupplierRequest::class => ['procurement', 'supplier_request'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry::class => ['production_labor', 'production_labor_output_entry'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual::class => ['production_labor', 'production_labor_payroll_accrual'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet::class => ['production_labor', 'production_labor_timesheet'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry::class => ['production_labor', 'production_labor_timesheet_entry'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder::class => ['production_labor', 'production_labor_work_order'],
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine::class => ['production_labor', 'production_labor_work_order_line'],
            \App\BusinessModules\Features\QualityControl\Models\QualityDefect::class => ['quality_executive_docs', 'quality_defect'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing::class => ['safety', 'safety_briefing'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction::class => ['safety', 'safety_corrective_action'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident::class => ['safety', 'safety_incident'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection::class => ['safety', 'safety_inspection'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding::class => ['safety', 'safety_inspection_finding'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation::class => ['safety', 'safety_violation'],
            \App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit::class => ['safety', 'safety_work_permit'],
            \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::class => ['site_request', 'site_request'],
            \App\Models\CustomerIssue::class => ['crm', 'customer_issue'],
            \App\Models\TimeEntry::class => ['time_tracking', 'time_entry'],
            \App\Models\Project::class => ['project', 'project'],
            \App\Models\Contract::class => ['contract', 'contract'],
            \App\Models\ContractPerformanceAct::class => ['performance_act', 'performance_act'],
            \App\Models\PerformanceActLine::class => ['performance_act', 'performance_act_line'],
            \App\Models\ProjectSchedule::class => ['schedule', 'schedule'],
            \App\Models\ScheduleTask::class => ['schedule', 'schedule_task'],
            \App\Models\CompletedWork::class => ['work_completion', 'completed_work'],
            \App\Models\ConstructionJournalEntry::class => ['construction_journal', 'construction_journal_entry'],
            \App\Models\EstimateTemplate::class => ['estimate_reference', 'estimate_template'],
            \App\Models\EstimateLibraryItem::class => ['estimate_reference', 'estimate_library_item'],
            \App\Models\EstimatePositionCatalog::class => ['estimate_reference', 'estimate_catalog_item'],
        ];
    }

    public static function models(): array
    {
        return array_keys(self::definitions());
    }

    public function saved(Model $model): void
    {
        $this->queue($model);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model);
    }

    public function restored(Model $model): void
    {
        $this->queue($model);
    }

    private function queue(Model $model): void
    {
        $transactional = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        try {
            $this->queueEntity($model);
        } catch (\Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            \Illuminate\Support\Facades\Log::warning('ai_assistant.rag.entity_queue_failed', ['model' => $model::class,
                'entity_id' => (string) $model->getKey(), 'exception_class' => $exception::class]);
        }
    }

    private function queueEntity(Model $model): void
    {
        if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
            return;
        }
        $definition = self::definitions()[$model::class] ?? null;
        if ($definition === null) {
            return;
        }
        [$sourceType, $entityType] = $definition;
        if (in_array(\App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::retrievalMode($entityType), ['live_only', 'unavailable'], true)) { return; }
        $entityId = $model->getKey();
        if ($entityType === 'estimate_template' && ($model->getAttribute('is_public') || $model->getRawOriginal('is_public'))) {
            app(GlobalRagQueue::class)->queueAfterCommit('estimate_reference', $entityType, $entityId);
        }
        $globalTypes = \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('globalCatalogEntities')
            + \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('organizationNullableCatalogs')
            + \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('publicCatalogEntities')
            + \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('globalFanoutEntities');
        if ($sourceType === 'knowledge' || ($sourceType === 'estimate_reference' && $model->getAttribute('organization_id') === null)
            || (isset($globalTypes[$entityType]) && ($model->getAttribute('organization_id') === null
                || isset(\App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('publicCatalogEntities')[$entityType])
                || isset(\App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('globalFanoutEntities')[$entityType])))) {
            app(GlobalRagQueue::class)->queueAfterCommit($sourceType, $entityType, $entityId);
            return;
        }
        if ($model instanceof \App\Models\User) {
            foreach ($model->organizations()->select('organizations.id')->lazyById(50, 'organizations.id', 'id') as $organization) {
                app(RagIndexingCoordinator::class)->queueEntity((int) $organization->id, null, $sourceType, $entityType, $entityId);
            }
        }
        if ($entityType === 'performance_act_line') {
            $entityType = 'performance_act';
            $entityId = $model->getAttribute('performance_act_id');
        }
        [$organizationId, $projectId] = $this->scope($model);
        if ($organizationId !== null && $entityId !== null) {
            app(RagIndexingCoordinator::class)->queueEntity($organizationId, $projectId, $sourceType, $entityType, $entityId);
            if ($entityType === 'design_artifact_version') {
                \App\Jobs\ScanAssistantDocuments::dispatch($organizationId)->afterCommit();
            }
        }
        foreach (RagSource::query()->where('source_type', $sourceType)->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId)->when($organizationId !== null, static fn ($query) => $query->where('organization_id', '!=', $organizationId))
            ->select(['organization_id','project_id'])->distinct()->cursor() as $source) {
            app(RagIndexingCoordinator::class)->queueEntity((int) $source->organization_id, $source->project_id, $sourceType, $entityType, $entityId);
        }
    }

    private function scope(Model $model, int $depth = 0): array
    {
        if ($depth > 5) {
            return [null, null];
        }
        $metadata = \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::class;
        $type = self::definitions()[$model::class][1] ?? null;
        $organizationColumns = $metadata::values('organizationColumns');
        $organizationColumn = array_key_exists($type, $organizationColumns) ? $organizationColumns[$type] : 'organization_id';
        $organizationId = $organizationColumn === null ? null : $model->getAttribute($organizationColumn);
        $projectId = $model instanceof \App\Models\Project ? $model->getKey() : $model->getAttribute('project_id');
        if (is_numeric($organizationId) && (int) $organizationId > 0) {
            return [(int) $organizationId, is_numeric($projectId) ? (int) $projectId : null];
        }
        foreach ($metadata::values('parentColumns')[$type] ?? [] as $column => $parentDefinition) {
            $parentId = $model->getAttribute($column);
            $parentClass = $metadata::values('entityDefinitions')[$parentDefinition['type']][1] ?? null;
            if ($parentId === null || $parentClass === null) { continue; }
            $parent = $parentClass::query()->withoutGlobalScopes()->where($parentDefinition['key'] ?? 'id', $parentId)->first();
            if ($parent instanceof Model) {
                [$org, $project] = $this->scope($parent, $depth + 1);
                if ($org !== null) { return [$org, is_numeric($projectId) ? (int) $projectId : $project]; }
            }
        }
        foreach (['project','contract','performanceAct','estimate','item','warehouse','asset','workOrder','timesheet','scope','session','package','checklist','documentSet','assignment','siteRequest','purchaseRequest','proposal','order','request','inspection'] as $relation) {
            if (! method_exists($model, $relation) || ! ($model->{$relation}() instanceof Relation)) {
                continue;
            }
            $parent = $model->getRelationValue($relation);
            if ($parent instanceof Model) {
                [$org, $project] = $this->scope($parent, $depth + 1);
                if ($org !== null) {
                    return [$org, is_numeric($projectId) ? (int) $projectId : $project];
                }
            }
        }
        return [null, null];
    }
}
