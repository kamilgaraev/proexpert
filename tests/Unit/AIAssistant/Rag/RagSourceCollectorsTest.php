<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeManagementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ConstructionJournalRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateReferenceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\HandoverAcceptanceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\MachineryRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PaymentRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PerformanceActRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProductionLaborRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectPulseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\QualityAndExecutiveDocsRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SafetyRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ScheduleRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SiteRequestRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WarehouseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkCompletionRagSource;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalLine;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestLine;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionCatalogCategory;
use App\Models\EstimateSection;
use App\Models\EstimateTemplate;
use App\Models\JournalWorkVolume;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\NormativeBaseType;
use App\Models\NormativeCollection;
use App\Models\NormativeRate;
use App\Models\NormativeSection;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use Tests\TestCase;

class RagSourceCollectorsTest extends TestCase
{
    public function test_remaining_collectors_scope_by_organization_and_project(): void
    {
        config()->set('ai-assistant.rag.enabled', false);

        [$organization, $projectA, $projectB, $user] = $this->seedRagDomainRecords();

        foreach ($this->collectors() as [$collector, $sourceType]) {
            $this->assertTrue($collector->enabled(), "{$sourceType} collector must ignore legacy enabled config");

            $allChunks = iterator_to_array($collector->collectForOrganization($organization->id));
            $projectChunks = iterator_to_array($collector->collectForOrganization($organization->id, $projectA->id));

            $this->assertNotEmpty($allChunks, "{$sourceType} collector returned no organization chunks");
            $this->assertNotEmpty($projectChunks, "{$sourceType} collector returned no project chunks");
            $this->assertSame(array_fill(0, count($allChunks), $sourceType), array_map(static fn ($chunk): string => $chunk->sourceType, $allChunks));
            $this->assertSame(array_fill(0, count($allChunks), $organization->id), array_map(static fn ($chunk): int => $chunk->organizationId, $allChunks));
            $this->assertSame(array_fill(0, count($projectChunks), $projectA->id), array_map(static fn ($chunk): int => (int) $chunk->projectId, $projectChunks));
            $this->assertNotContains($projectB->id, array_map(static fn ($chunk): ?int => $chunk->projectId, $projectChunks));
            $this->assertNotSame('', trim($projectChunks[0]->content));
            $this->assertNotSame('', trim($projectChunks[0]->title));
            $this->assertNotEmpty($projectChunks[0]->metadata);
        }
    }

    public function test_expanded_collectors_scope_by_organization_and_project(): void
    {
        config()->set('ai-assistant.rag.enabled', false);

        [$organization, $projectA, $projectB] = $this->seedExpandedRagDomainRecords();

        foreach ($this->expandedCollectors() as [$collector, $sourceType]) {
            $allChunks = iterator_to_array($collector->collectForOrganization($organization->id));
            $projectChunks = iterator_to_array($collector->collectForOrganization($organization->id, $projectA->id));

            $this->assertNotEmpty($allChunks, "{$sourceType} collector returned no organization chunks");
            $this->assertNotEmpty($projectChunks, "{$sourceType} collector returned no project chunks");
            $this->assertSame(array_fill(0, count($allChunks), $sourceType), array_map(static fn ($chunk): string => $chunk->sourceType, $allChunks));
            $this->assertSame(array_fill(0, count($allChunks), $organization->id), array_map(static fn ($chunk): int => $chunk->organizationId, $allChunks));
            $this->assertSame(array_fill(0, count($projectChunks), $projectA->id), array_map(static fn ($chunk): int => (int) $chunk->projectId, $projectChunks));
            $this->assertNotContains($projectB->id, array_map(static fn ($chunk): ?int => $chunk->projectId, $projectChunks));
            $this->assertNotSame('', trim($projectChunks[0]->content));
            $this->assertNotSame('', trim($projectChunks[0]->title));
            $this->assertNotEmpty($projectChunks[0]->metadata);
        }
    }

    public function test_estimate_reference_collector_indexes_organization_references(): void
    {
        [$organization] = $this->seedExpandedRagDomainRecords();

        $chunks = iterator_to_array((new EstimateReferenceRagSource)->collectForOrganization($organization->id));
        $entityTypes = array_map(static fn ($chunk): string => $chunk->entityType, $chunks);

        $this->assertContains('estimate_template', $entityTypes);
        $this->assertContains('estimate_library_item', $entityTypes);
        $this->assertContains('estimate_catalog_item', $entityTypes);
        $this->assertContains('normative_rate', $entityTypes);
        $this->assertSame(array_fill(0, count($chunks), $organization->id), array_map(static fn ($chunk): int => $chunk->organizationId, $chunks));
        $this->assertContains('estimate_reference', array_map(static fn ($chunk): string => $chunk->sourceType, $chunks));
        $this->assertTrue(collect($chunks)->contains(static fn ($chunk): bool => str_contains($chunk->content, 'Foundation estimate template')));
        $this->assertTrue(collect($chunks)->contains(static fn ($chunk): bool => $chunk->metadata['reference_kind'] === 'normative_rate'));
    }

    public function test_estimate_collector_indexes_sections_with_their_positions(): void
    {
        [$organization, $projectA, $projectB] = $this->seedExpandedRagDomainRecords();
        $estimate = Estimate::query()->where('project_id', $projectA->id)->firstOrFail();
        $parentSection = EstimateSection::query()->where('estimate_id', $estimate->id)->firstOrFail();
        $childSection = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'parent_section_id' => $parentSection->id,
            'section_number' => '1.1',
            'name' => 'Pile works A',
            'sort_order' => 2,
            'section_total_amount' => 20000,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $childSection->id,
            'item_type' => 'work',
            'position_number' => '1.1-A',
            'name' => 'Pile cap concrete A',
            'quantity' => 4,
            'quantity_total' => 4,
            'unit_price' => 5000,
            'current_unit_price' => 5000,
            'total_amount' => 20000,
            'current_total_amount' => 20000,
        ]);

        $chunks = iterator_to_array((new EstimateRagSource)->collectForOrganization($organization->id, $projectA->id));
        $sectionChunk = collect($chunks)->first(static fn ($chunk): bool => $chunk->entityType === 'estimate_section');

        $this->assertNotNull($sectionChunk);
        $this->assertSame($organization->id, $sectionChunk->organizationId);
        $this->assertSame($projectA->id, $sectionChunk->projectId);
        $this->assertSame('estimate', $sectionChunk->sourceType);
        $this->assertStringContainsString('Foundation section A', $sectionChunk->title);
        $this->assertStringContainsString('Foundation section A', $sectionChunk->content);
        $this->assertStringContainsString('Concrete foundation A', $sectionChunk->content);
        $this->assertStringContainsString('Pile cap concrete A', $sectionChunk->content);
        $this->assertStringContainsString('FER06-01-001', $sectionChunk->content);
        $this->assertSame(2, $sectionChunk->metadata['items_count']);
        $this->assertSame(120000.0, $sectionChunk->metadata['section_total_amount']);
        $this->assertNotSame($projectB->id, $sectionChunk->projectId);
    }

    public function test_estimate_collector_collects_single_section_entity(): void
    {
        [$organization, $projectA] = $this->seedExpandedRagDomainRecords();

        $section = EstimateSection::query()
            ->whereHas('estimate', static fn ($query) => $query->where('project_id', $projectA->id))
            ->firstOrFail();

        $chunks = iterator_to_array((new EstimateRagSource)->collectEntity(
            $organization->id,
            'estimate_section',
            $section->id
        ));

        $this->assertCount(1, $chunks);
        $this->assertSame('estimate_section', $chunks[0]->entityType);
        $this->assertSame($section->id, $chunks[0]->entityId);
        $this->assertSame($section->estimate_id, $chunks[0]->metadata['estimate_id']);
        $this->assertStringContainsString('Concrete foundation A', $chunks[0]->content);
    }

    public function test_operational_collectors_index_expanded_entity_types(): void
    {
        [$organization, $project, $user, $material] = $this->seedRagCoreRecords();

        $this->seedProcurementEntities($organization, $project, $user, $material);
        $this->seedWarehouseEntities($organization, $project, $user, $material);
        $this->seedScheduleEntities($organization, $project, $user);

        $this->assertCollectorEntityTypes(new ProcurementRagSource, $organization->id, [
            'purchase_request',
            'supplier_request',
            'supplier_proposal',
            'supplier_proposal_decision',
            'purchase_order',
            'purchase_receipt',
            'procurement_approval',
            'procurement_audit_event',
        ]);

        $this->assertCollectorEntityTypes(new WarehouseRagSource, $organization->id, [
            'project_material_delivery',
            'warehouse_balance',
            'warehouse_movement',
            'warehouse_project_allocation',
            'asset_reservation',
            'inventory_act',
            'warehouse_storage_cell',
            'warehouse_task',
            'warehouse_asset',
        ]);

        $this->assertCollectorEntityTypes(new ScheduleRagSource, $organization->id, [
            'schedule',
            'schedule_task',
        ]);
    }

    public function test_new_domain_collectors_index_all_supported_entity_types(): void
    {
        [$organization, $project, $user, $material] = $this->seedRagCoreRecords();
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Concrete works',
            'code' => 'WT-CONCRETE',
            'is_active' => true,
        ]);

        $this->seedSafetyEntities($organization, $project, $user);
        $this->seedMachineryEntities($organization, $project, $user);
        $this->seedProductionLaborEntities($organization, $project, $user, $workType);
        $this->seedChangeManagementEntities($organization, $project, $user);
        $this->seedHandoverAcceptanceEntities($organization, $project, $user);

        $this->assertCollectorEntityTypes(new SafetyRagSource, $organization->id, [
            'safety_incident',
            'safety_violation',
            'safety_work_permit',
            'safety_briefing',
            'safety_corrective_action',
        ]);

        $this->assertCollectorEntityTypes(new MachineryRagSource, $organization->id, [
            'machinery_asset',
            'machinery_assignment',
            'machinery_shift_report',
            'machinery_downtime',
            'machinery_maintenance_order',
            'machinery_fuel_issue',
            'machinery_production_record',
        ]);

        $this->assertCollectorEntityTypes(new ProductionLaborRagSource, $organization->id, [
            'production_labor_work_order',
            'production_labor_work_order_line',
            'production_labor_timesheet',
            'production_labor_timesheet_entry',
            'production_labor_output_entry',
            'production_labor_payroll_accrual',
        ]);

        $this->assertCollectorEntityTypes(new ChangeManagementRagSource, $organization->id, [
            'change_management_rfi',
            'change_request',
            'change_claim',
            'change_impact',
            'change_approval',
            'variation_order',
        ]);

        $this->assertCollectorEntityTypes(new HandoverAcceptanceRagSource, $organization->id, [
            'project_location',
            'acceptance_scope',
            'acceptance_session',
            'acceptance_checklist',
            'acceptance_checklist_item',
            'acceptance_finding',
            'acceptance_signoff',
            'handover_package',
            'handover_package_document',
        ]);
    }

    private function assertCollectorEntityTypes(RagSourceCollectorInterface $collector, int $organizationId, array $expectedTypes): void
    {
        $chunks = iterator_to_array($collector->collectForOrganization($organizationId));
        $actualTypes = array_values(array_unique(array_map(static fn ($chunk): string => $chunk->entityType, $chunks)));

        foreach ($expectedTypes as $expectedType) {
            $this->assertContains($expectedType, $actualTypes, "{$collector->sourceType()} missed {$expectedType}");
        }

        $this->assertSame(
            array_fill(0, count($chunks), $collector->sourceType()),
            array_map(static fn ($chunk): string => $chunk->sourceType, $chunks)
        );
        $this->assertFalse(
            collect($chunks)->contains(static fn ($chunk): bool => trim($chunk->title) === '' || trim($chunk->content) === ''),
            "{$collector->sourceType()} produced empty title or content"
        );
    }

    private function seedRagCoreRecords(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'RAG coverage project']);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Concrete B25',
            'code' => 'RAG-MAT',
            'is_active' => true,
            'additional_properties' => ['asset_type' => 'material'],
        ]);

        return [$organization, $project, $user, $material];
    }

    private function seedProcurementEntities(Organization $organization, Project $project, User $user, Material $material): void
    {
        $siteRequest = $this->siteRequest($organization->id, $project->id, $user->id, 'Procurement RAG site request');
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteRequest->id,
            'assigned_to' => $user->id,
            'request_number' => 'RAG-PR-001',
            'status' => 'approved',
            'needed_by' => now()->addWeek()->toDateString(),
            'budget_amount' => 100000,
            'budget_currency' => 'RUB',
        ]);
        $purchaseRequestLine = PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'material_id' => $material->id,
            'name' => 'Concrete B25',
            'quantity' => 10,
            'unit' => 'm3',
        ]);
        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'RAG-SR-001',
            'status' => 'sent',
            'sent_at' => now(),
            'comment' => 'Request supplier price',
        ]);
        $supplierRequestLine = SupplierRequestLine::query()->create([
            'supplier_request_id' => $supplierRequest->id,
            'purchase_request_line_id' => $purchaseRequestLine->id,
            'material_id' => $material->id,
            'name' => 'Concrete B25',
            'quantity' => 10,
            'unit' => 'm3',
        ]);
        $proposal = SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'RAG-SP-001',
            'proposal_date' => now()->toDateString(),
            'status' => 'submitted',
            'subtotal_amount' => 90000,
            'delivery_amount' => 5000,
            'vat_amount' => 19000,
            'total_amount' => 114000,
            'currency' => 'RUB',
            'valid_until' => now()->addMonth()->toDateString(),
        ]);
        SupplierProposalLine::query()->create([
            'supplier_proposal_id' => $proposal->id,
            'supplier_request_line_id' => $supplierRequestLine->id,
            'material_id' => $material->id,
            'name' => 'Concrete B25',
            'quantity' => 10,
            'unit' => 'm3',
            'unit_price' => 9000,
            'total_amount' => 90000,
        ]);
        SupplierProposalDecision::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'selected',
            'is_lowest_price_selected' => true,
            'decision_reason' => 'Best price',
            'selected_by' => $user->id,
            'selected_at' => now(),
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'accepted_supplier_proposal_id' => $proposal->id,
            'order_number' => 'RAG-PO-001',
            'order_date' => now()->toDateString(),
            'status' => 'sent',
            'total_amount' => 114000,
            'currency' => 'RUB',
            'pricing_source' => 'accepted_supplier_proposal',
        ]);
        $orderItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'material_id' => $material->id,
            'material_name' => 'Concrete B25',
            'quantity' => 10,
            'unit' => 'm3',
            'unit_price' => 9000,
            'total_price' => 90000,
        ]);
        $warehouse = $this->warehouse($organization);
        $receipt = PurchaseReceipt::query()->create([
            'organization_id' => $organization->id,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'received_by_user_id' => $user->id,
            'receipt_number' => 'RAG-REC-001',
            'receipt_date' => now()->toDateString(),
            'status' => 'posted',
        ]);
        PurchaseReceiptLine::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $orderItem->id,
            'quantity_received' => 10,
            'price' => 9000,
            'total_amount' => 90000,
        ]);
        ProcurementApproval::query()->create([
            'organization_id' => $organization->id,
            'approvable_type' => SupplierProposalDecision::class,
            'approvable_id' => SupplierProposalDecision::query()->where('supplier_request_id', $supplierRequest->id)->value('id'),
            'reason_code' => 'proposal_decision',
            'status' => 'approved',
            'requested_by' => $user->id,
            'approved_by' => $user->id,
            'requested_at' => now(),
            'resolved_at' => now(),
        ]);
        ProcurementAuditEvent::query()->create([
            'organization_id' => $organization->id,
            'subject_type' => PurchaseOrder::class,
            'subject_id' => $order->id,
            'event_type' => 'purchase_order_created',
            'actor_id' => $user->id,
            'occurred_at' => now(),
            'payload' => ['order_number' => 'RAG-PO-001'],
        ]);
    }

    private function seedWarehouseEntities(Organization $organization, Project $project, User $user, Material $material): void
    {
        $warehouse = $this->warehouse($organization);
        $siteRequest = $this->siteRequest($organization->id, $project->id, $user->id, 'Warehouse RAG delivery');
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteRequest->id,
            'request_number' => 'RAG-WH-PR-001',
            'status' => 'approved',
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 20,
            'reserved_quantity' => 4,
            'unit_price' => 9000,
            'min_stock_level' => 5,
            'max_stock_level' => 100,
            'last_movement_at' => now(),
        ]);
        WarehouseProjectAllocation::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'allocated_quantity' => 6,
            'allocated_by_user_id' => $user->id,
            'allocated_at' => now(),
        ]);
        ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'site_request_id' => $siteRequest->id,
            'purchase_request_id' => $purchaseRequest->id,
            'status' => 'reserved',
            'requested_quantity' => 6,
            'reserved_quantity' => 6,
            'planned_delivery_date' => now()->addDays(3)->toDateString(),
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => 'receipt',
            'quantity' => 20,
            'price' => 9000,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'document_number' => 'RAG-MOV-001',
            'movement_date' => now(),
        ]);
        AssetReservation::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 3,
            'project_id' => $project->id,
            'reserved_by' => $user->id,
            'status' => 'active',
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'reason' => 'Project allocation',
        ]);
        $inventoryAct = InventoryAct::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'RAG-INV-001',
            'status' => 'completed',
            'inventory_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $inventoryAct->id,
            'material_id' => $material->id,
            'expected_quantity' => 20,
            'actual_quantity' => 19,
            'difference' => -1,
            'unit_price' => 9000,
            'total_value' => -9000,
        ]);
        WarehouseStorageCell::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'name' => 'Cell A1',
            'code' => 'RAG-A1',
            'cell_type' => 'storage',
            'status' => 'available',
            'is_active' => true,
        ]);
        WarehouseTask::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'task_number' => 'RAG-WT-001',
            'title' => 'Pick concrete',
            'task_type' => 'picking',
            'status' => 'queued',
            'priority' => 'normal',
            'planned_quantity' => 6,
            'due_at' => now()->addDay(),
            'created_by_id' => $user->id,
        ]);
    }

    private function seedScheduleEntities(Organization $organization, Project $project, User $user): void
    {
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'name' => 'RAG schedule',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'assigned_user_id' => $user->id,
            'name' => 'RAG schedule task',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addWeek()->toDateString(),
            'planned_duration_days' => 7,
            'status' => 'in_progress',
            'priority' => 'normal',
            'progress_percent' => 25,
        ]);
    }

    private function seedSafetyEntities(Organization $organization, Project $project, User $user): void
    {
        $incident = SafetyIncident::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'assigned_to_user_id' => $user->id,
            'incident_number' => 'RAG-SI-001',
            'title' => 'Safety incident',
            'incident_type' => 'near_miss',
            'severity' => 'major',
            'status' => 'reported',
            'occurred_at' => now(),
            'description' => 'Incident description',
        ]);
        $violation = SafetyViolation::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'assigned_to_user_id' => $user->id,
            'violation_number' => 'RAG-SV-001',
            'title' => 'Safety violation',
            'severity' => 'major',
            'status' => 'open',
            'description' => 'Violation description',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        SafetyWorkPermit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $user->id,
            'permit_number' => 'RAG-SWP-001',
            'title' => 'Hot works',
            'permit_type' => 'hot_work',
            'risk_level' => 'high',
            'valid_from' => now(),
            'valid_until' => now()->addDay(),
            'status' => 'approved',
        ]);
        SafetyBriefing::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'conducted_by_user_id' => $user->id,
            'briefing_number' => 'RAG-SB-001',
            'title' => 'Safety briefing',
            'briefing_type' => 'daily',
            'conducted_at' => now(),
            'topics' => ['PPE'],
        ]);
        SafetyCorrectiveAction::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'incident_id' => $incident->id,
            'violation_id' => $violation->id,
            'assigned_to_user_id' => $user->id,
            'action_number' => 'RAG-SCA-001',
            'title' => 'Correct safety gap',
            'description' => 'Corrective action',
            'source_type' => 'incident',
            'severity' => 'major',
            'status' => 'open',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    private function seedMachineryEntities(Organization $organization, Project $project, User $user): void
    {
        $asset = MachineryAsset::query()->create([
            'organization_id' => $organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'RAG-MA-001',
            'name' => 'Excavator',
            'inventory_number' => 'EX-1',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 1000,
            'fuel_type' => 'diesel',
            'fuel_consumption_rate' => 12,
            'meter_hours' => 100,
        ]);
        $assignment = MachineryAssignment::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $user->id,
            'status' => 'active',
            'planned_start_at' => now(),
            'planned_end_at' => now()->addHours(8),
            'planned_hours' => 8,
        ]);
        $report = MachineryShiftReport::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'reported_by_user_id' => $user->id,
            'report_date' => now()->toDateString(),
            'status' => 'submitted',
            'planned_hours' => 8,
            'actual_hours' => 7,
            'fuel_consumed' => 80,
            'work_description' => 'Excavation',
        ]);
        MachineryDowntime::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'shift_report_id' => $report->id,
            'reason' => 'maintenance',
            'started_at' => now(),
            'duration_minutes' => 30,
        ]);
        MachineryMaintenanceOrder::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $user->id,
            'order_number' => 'RAG-MMO-001',
            'title' => 'Service excavator',
            'maintenance_type' => 'repair',
            'priority' => 'normal',
            'status' => 'open',
            'cost' => 15000,
        ]);
        MachineryFuelIssue::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'issued_by_user_id' => $user->id,
            'issued_at' => now(),
            'fuel_type' => 'diesel',
            'quantity' => 80,
            'unit' => 'l',
            'cost' => 6000,
        ]);
        MachineryProductionRecord::query()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'shift_report_id' => $report->id,
            'recorded_by_user_id' => $user->id,
            'recorded_at' => now(),
            'quantity' => 120,
            'unit' => 'm3',
            'comment' => 'Moved soil',
        ]);
    }

    private function seedProductionLaborEntities(Organization $organization, Project $project, User $user, WorkType $workType): void
    {
        $workOrder = ProductionLaborWorkOrder::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'order_number' => 'RAG-PLO-001',
            'title' => 'Concrete crew',
            'assignee_type' => 'brigade',
            'assignee_name' => 'Crew 1',
            'planned_start_date' => now()->toDateString(),
            'planned_finish_date' => now()->addWeek()->toDateString(),
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $line = ProductionLaborWorkOrderLine::query()->create([
            'organization_id' => $organization->id,
            'work_order_id' => $workOrder->id,
            'work_type_id' => $workType->id,
            'name' => 'Concrete placement',
            'unit' => 'm3',
            'planned_quantity' => 30,
            'accepted_quantity' => 10,
            'unit_rate' => 1000,
            'planned_hours' => 40,
            'hour_rate' => 500,
            'pay_basis' => 'volume',
        ]);
        $timesheet = ProductionLaborTimesheet::query()->create([
            'organization_id' => $organization->id,
            'work_order_id' => $workOrder->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'shift_date' => now()->toDateString(),
            'status' => 'submitted',
        ]);
        ProductionLaborTimesheetEntry::query()->create([
            'organization_id' => $organization->id,
            'timesheet_id' => $timesheet->id,
            'work_order_line_id' => $line->id,
            'user_id' => $user->id,
            'worker_name' => 'Worker One',
            'hours' => 8,
            'include_in_payroll' => true,
        ]);
        ProductionLaborOutputEntry::query()->create([
            'organization_id' => $organization->id,
            'work_order_id' => $workOrder->id,
            'work_order_line_id' => $line->id,
            'project_id' => $project->id,
            'recorded_by_user_id' => $user->id,
            'work_date' => now()->toDateString(),
            'quantity' => 10,
            'hours' => 8,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        ProductionLaborPayrollAccrual::query()->create([
            'organization_id' => $organization->id,
            'work_order_id' => $workOrder->id,
            'work_order_line_id' => $line->id,
            'project_id' => $project->id,
            'period_start' => now()->startOfWeek()->toDateString(),
            'period_end' => now()->endOfWeek()->toDateString(),
            'accepted_quantity' => 10,
            'accepted_hours' => 8,
            'amount' => 10000,
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $user->id,
        ]);
    }

    private function seedChangeManagementEntities(Organization $organization, Project $project, User $user): void
    {
        $rfi = ChangeManagementRfi::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'rfi_number' => 'RAG-RFI-001',
            'subject' => 'Clarify slab detail',
            'question' => 'Need updated detail',
            'addressee_type' => 'customer',
            'status' => 'sent',
            'response_due_date' => now()->addDays(5)->toDateString(),
            'sent_at' => now(),
        ]);
        $request = ChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'related_rfi_id' => $rfi->id,
            'change_number' => 'RAG-CR-001',
            'title' => 'Change slab reinforcement',
            'reason' => 'design_update',
            'description' => 'Additional reinforcement',
            'initiator_type' => 'customer',
            'status' => 'approved',
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        ChangeClaim::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'change_request_id' => $request->id,
            'created_by_user_id' => $user->id,
            'claim_number' => 'RAG-CC-001',
            'title' => 'Cost claim',
            'description' => 'Additional works cost',
            'amount' => 50000,
            'status' => 'submitted',
        ]);
        ChangeImpact::query()->create([
            'organization_id' => $organization->id,
            'change_request_id' => $request->id,
            'cost_delta' => 50000,
            'schedule_delta_days' => 3,
            'requires_contract_change' => true,
            'requires_estimate_revision' => true,
            'requires_procurement_update' => false,
            'requires_customer_approval' => true,
            'summary' => 'Cost and schedule impact',
        ]);
        ChangeApproval::query()->create([
            'organization_id' => $organization->id,
            'change_request_id' => $request->id,
            'approved_by_user_id' => $user->id,
            'approval_type' => 'customer',
            'status' => 'approved',
            'comment' => 'Approved',
            'decided_at' => now(),
        ]);
        VariationOrder::query()->create([
            'organization_id' => $organization->id,
            'change_request_id' => $request->id,
            'variation_number' => 'RAG-VO-001',
            'amount' => 50000,
            'schedule_delta_days' => 3,
            'description' => 'Variation order',
        ]);
    }

    private function seedHandoverAcceptanceEntities(Organization $organization, Project $project, User $user): void
    {
        $location = ProjectLocation::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'location_type' => 'floor',
            'name' => 'Floor 1',
            'code' => 'RAG-F1',
            'path' => 'Floor 1',
            'level' => 1,
        ]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'project_location_id' => $location->id,
            'created_by_user_id' => $user->id,
            'title' => 'Floor 1 acceptance',
            'description' => 'Accept floor 1',
            'status' => 'planned',
            'planned_acceptance_date' => now()->addWeek()->toDateString(),
        ]);
        $session = AcceptanceSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $user->id,
            'scheduled_at' => now()->addDays(2),
            'status' => 'planned',
            'participant_user_ids' => [$user->id],
            'summary' => 'Acceptance session',
        ]);
        $checklist = AcceptanceChecklist::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'title' => 'Acceptance checklist',
            'status' => 'draft',
        ]);
        AcceptanceChecklistItem::query()->create([
            'acceptance_checklist_id' => $checklist->id,
            'title' => 'Check concrete finish',
            'is_required' => true,
            'status' => 'pending',
        ]);
        AcceptanceFinding::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'acceptance_session_id' => $session->id,
            'created_by_user_id' => $user->id,
            'title' => 'Finding',
            'description' => 'Fix minor defect',
            'severity' => 'major',
            'status' => 'open',
        ]);
        AcceptanceSignoff::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $user->id,
            'status' => 'signed',
            'comment' => 'Signed',
            'signed_at' => now(),
        ]);
        $package = HandoverPackage::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $user->id,
            'title' => 'Handover package',
            'status' => 'draft',
        ]);
        HandoverPackageDocument::query()->create([
            'handover_package_id' => $package->id,
            'title' => 'Executive document',
            'document_type' => 'executive_document',
            'is_required' => true,
            'status' => 'missing',
        ]);
    }

    private function warehouse(Organization $organization): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'code' => 'RAG-WH'],
            [
                'name' => 'RAG warehouse',
                'warehouse_type' => 'central',
                'is_main' => true,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<int, array{0: RagSourceCollectorInterface, 1: string}>
     */
    private function collectors(): array
    {
        return [
            [new ProcurementRagSource, 'procurement'],
            [new WarehouseRagSource, 'warehouse'],
            [new SiteRequestRagSource, 'site_request'],
            [new WorkCompletionRagSource, 'work_completion'],
            [new ProjectPulseRagSource, 'project_pulse'],
        ];
    }

    /**
     * @return array<int, array{0: RagSourceCollectorInterface, 1: string}>
     */
    private function expandedCollectors(): array
    {
        return [
            [new EstimateRagSource, 'estimate'],
            [new ConstructionJournalRagSource, 'construction_journal'],
            [new PerformanceActRagSource, 'performance_act'],
            [new PaymentRagSource, 'payment'],
            [new QualityAndExecutiveDocsRagSource, 'quality_executive_docs'],
        ];
    }

    /**
     * @return array{0: Organization, 1: Project, 2: Project, 3: User}
     */
    private function seedRagDomainRecords(): array
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Бетон B25',
            'code' => 'MAT-B25',
            'is_active' => true,
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Монтаж перекрытий',
            'code' => 'WT-SLAB',
            'is_active' => true,
        ]);

        $siteA = $this->siteRequest($organization->id, $projectA->id, $user->id, 'Материалы для секции А');
        $siteB = $this->siteRequest($organization->id, $projectB->id, $user->id, 'Материалы для секции Б');
        $this->siteRequest($foreignOrganization->id, $foreignProject->id, $user->id, 'Чужая заявка');

        $purchaseA = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteA->id,
            'assigned_to' => $user->id,
            'request_number' => 'PR-A',
            'status' => 'pending',
            'needed_by' => now()->addDays(3)->toDateString(),
            'budget_amount' => 120000,
            'budget_currency' => 'RUB',
            'notes' => 'Проверить срок поставки',
        ]);
        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseA->id,
            'material_id' => $material->id,
            'name' => 'Бетон B25',
            'quantity' => 12,
            'unit' => 'м3',
        ]);
        PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteB->id,
            'request_number' => 'PR-B',
            'status' => 'draft',
        ]);

        ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'material_id' => $material->id,
            'site_request_id' => $siteA->id,
            'purchase_request_id' => $purchaseA->id,
            'status' => 'reserved',
            'requested_quantity' => 12,
            'reserved_quantity' => 8,
            'planned_delivery_date' => now()->addDays(2)->toDateString(),
        ]);
        ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'material_id' => $material->id,
            'status' => 'requested',
            'requested_quantity' => 5,
        ]);

        CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'quantity' => 10,
            'completed_quantity' => 10,
            'price' => 5000,
            'total_amount' => 50000,
            'completion_date' => now()->toDateString(),
            'status' => 'confirmed',
            'notes' => 'Принято технадзором',
        ]);
        CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'quantity' => 4,
            'completion_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        ProjectPulseReport::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'status' => 'risk',
            'ai_status' => 'rules_only',
            'summary' => ['text' => 'Есть риск по срокам'],
            'metrics' => ['risk_count' => 1],
            'urgent_actions' => [['title' => 'Ускорить поставку']],
            'risk_groups' => [['title' => 'График']],
            'finance' => [],
            'activity' => [],
            'recommendations' => [['title' => 'Проверить закупку']],
            'generated_at' => now(),
        ]);
        ProjectPulseReport::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'status' => 'good',
            'summary' => ['text' => 'Без критичных рисков'],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [],
            'activity' => [],
            'recommendations' => [],
            'generated_at' => now(),
        ]);

        return [$organization, $projectA, $projectB, $user];
    }

    /**
     * @return array{0: Organization, 1: Project, 2: Project}
     */
    private function seedExpandedRagDomainRecords(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Warehouse construction A']);
        $projectB = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Warehouse construction B']);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'MTM Build',
            'contractor_type' => 'manual',
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'cubic meter',
            'short_name' => 'm3',
            'type' => 'work',
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Concrete foundation',
            'code' => 'FOUND',
            'is_active' => true,
        ]);
        $baseType = NormativeBaseType::query()->create([
            'code' => 'FER',
            'name' => 'Federal rates',
            'is_active' => true,
        ]);
        $collection = NormativeCollection::query()->create([
            'base_type_id' => $baseType->id,
            'code' => '06',
            'name' => 'Concrete works',
            'is_active' => true,
        ]);
        $section = NormativeSection::query()->create([
            'collection_id' => $collection->id,
            'code' => '06-01',
            'name' => 'Foundations',
        ]);
        $rate = NormativeRate::query()->create([
            'collection_id' => $collection->id,
            'section_id' => $section->id,
            'code' => 'FER06-01-001',
            'name' => 'Foundation concrete placement',
            'measurement_unit' => 'm3',
            'base_price' => 1000,
            'materials_cost' => 600,
            'machinery_cost' => 200,
            'labor_cost' => 200,
        ]);
        $category = EstimatePositionCatalogCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Concrete works',
            'is_active' => true,
        ]);
        EstimatePositionCatalog::query()->create([
            'organization_id' => $organization->id,
            'category_id' => $category->id,
            'name' => 'Concrete B25 placement',
            'code' => 'CAT-CONCRETE-B25',
            'item_type' => 'work',
            'measurement_unit_id' => $unit->id,
            'work_type_id' => $workType->id,
            'unit_price' => 5000,
            'direct_costs' => 4000,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);
        EstimateTemplate::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Foundation estimate template',
            'description' => 'Template for concrete foundations',
            'work_type_category' => 'general_construction',
            'template_structure' => [['name' => 'Foundations']],
            'is_public' => false,
            'created_by_user_id' => $user->id,
        ]);
        $library = EstimateLibrary::query()->create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Foundation library',
            'category' => 'foundations',
            'access_level' => 'organization',
            'is_active' => true,
        ]);
        EstimateLibraryItem::query()->create([
            'library_id' => $library->id,
            'name' => 'Foundation package',
            'description' => 'Reusable foundation work package',
            'positions_count' => 1,
        ]);

        $this->seedExpandedProjectRecords($organization, $projectA, $user, $contractor, $workType, $unit, $rate, 'A');
        $this->seedExpandedProjectRecords($organization, $projectB, $user, $contractor, $workType, $unit, $rate, 'B');

        return [$organization, $projectA, $projectB];
    }

    private function seedExpandedProjectRecords(
        Organization $organization,
        Project $project,
        User $user,
        Contractor $contractor,
        WorkType $workType,
        MeasurementUnit $unit,
        NormativeRate $rate,
        string $suffix
    ): void {
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => "CTR-{$suffix}",
            'date' => now()->toDateString(),
            'subject' => "Construction contract {$suffix}",
            'total_amount' => 1000000,
            'base_amount' => 1000000,
            'status' => 'active',
            'is_fixed_amount' => true,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'number' => "EST-{$suffix}",
            'name' => "Warehouse estimate {$suffix}",
            'description' => "Estimate description {$suffix}",
            'status' => 'approved',
            'type' => 'local',
            'estimate_date' => now()->toDateString(),
            'total_direct_costs' => 100000,
            'total_amount' => 120000,
            'total_amount_with_vat' => 144000,
        ]);
        $estimateSection = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => "1{$suffix}",
            'name' => "Foundation section {$suffix}",
            'sort_order' => 1,
            'section_total_amount' => 120000,
        ]);
        $estimateItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $estimateSection->id,
            'normative_rate_id' => $rate->id,
            'normative_rate_code' => $rate->code,
            'item_type' => 'work',
            'position_number' => "1-{$suffix}",
            'name' => "Concrete foundation {$suffix}",
            'work_type_id' => $workType->id,
            'measurement_unit_id' => $unit->id,
            'quantity' => 20,
            'quantity_total' => 20,
            'unit_price' => 5000,
            'current_unit_price' => 5000,
            'total_amount' => 100000,
            'current_total_amount' => 100000,
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => "General journal {$suffix}",
            'journal_number' => "J-{$suffix}",
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 1,
            'work_description' => "Concrete foundation poured {$suffix}",
            'status' => 'submitted',
            'created_by_user_id' => $user->id,
            'problems_description' => "Minor delay {$suffix}",
            'quality_notes' => "Quality note {$suffix}",
        ]);
        JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $estimateItem->id,
            'work_type_id' => $workType->id,
            'quantity' => 10,
            'measurement_unit_id' => $unit->id,
        ]);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => "ACT-{$suffix}",
            'act_date' => now()->toDateString(),
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'amount' => 80000,
            'description' => "Performance act {$suffix}",
            'status' => 'pending_approval',
            'is_approved' => false,
        ]);
        PerformanceActLine::query()->create([
            'performance_act_id' => $act->id,
            'estimate_item_id' => $estimateItem->id,
            'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => "Manual act line {$suffix}",
            'unit' => 'm3',
            'quantity' => 10,
            'unit_price' => 5000,
            'amount' => 50000,
        ]);
        PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'payment_request',
            'document_number' => "PAY-{$suffix}",
            'document_date' => now()->toDateString(),
            'direction' => 'outgoing',
            'invoice_type' => 'act',
            'contractor_id' => $contractor->id,
            'amount' => 50000,
            'currency' => 'RUB',
            'paid_amount' => 10000,
            'remaining_amount' => 40000,
            'status' => 'pending_approval',
            'workflow_stage' => 'approval',
            'due_date' => now()->addWeek()->toDateString(),
            'payment_purpose' => "Payment purpose {$suffix}",
        ]);
        QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'created_by' => $user->id,
            'assigned_to' => $user->id,
            'defect_number' => "DEF-{$suffix}",
            'title' => "Concrete surface defect {$suffix}",
            'description' => "Quality defect {$suffix}",
            'severity' => 'critical',
            'status' => 'open',
            'location_name' => "Zone {$suffix}",
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        $documentSet = ExecutiveDocumentSet::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'set_number' => "SET-{$suffix}",
            'title' => "Executive set {$suffix}",
            'status' => 'under_review',
            'stage_name' => 'Foundations',
            'zone_name' => "Zone {$suffix}",
            'planned_transmittal_date' => now()->addDays(3)->toDateString(),
        ]);
        ExecutiveDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_set_id' => $documentSet->id,
            'created_by' => $user->id,
            'document_type' => 'hidden_work_act',
            'title' => "Hidden work act {$suffix}",
            'status' => 'remarks',
            'work_type_name' => 'Concrete foundation',
            'section_name' => "Section {$suffix}",
            'journal_entry_id' => $entry->id,
            'inspection_date' => now()->toDateString(),
        ]);
    }

    private function siteRequest(int $organizationId, int $projectId, int $userId, string $title): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'user_id' => $userId,
            'title' => $title,
            'description' => 'Нужны материалы на объект',
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::HIGH->value,
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'required_date' => now()->addDays(5)->toDateString(),
            'material_name' => 'Бетон B25',
            'material_quantity' => 12,
            'material_unit' => 'м3',
        ]);
    }
}
