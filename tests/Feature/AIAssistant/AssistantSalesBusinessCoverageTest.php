<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementBusinessRagSource;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmTimelineEvent;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimate;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateLineItem;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateVersion;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Supplier;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSalesBusinessCoverageTest extends TestCase
{
    public function test_procurement_lines_inherit_current_project_before_limit_and_are_not_truncated_to_five(): void
    {
        $fixture = $this->fixture();
        $hidden = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $visible = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $hiddenOrder = $this->order($fixture->organization, $hidden);
        $visibleOrder = $this->order($fixture->organization, $visible);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $foreignOrder = $this->order($fixture->foreignOrganization, $foreignProject);
        for ($index = 0; $index < 25; $index++) { $this->line($hiddenOrder, 'Скрытая '.$index); }
        $allowed = [];
        for ($index = 0; $index < 8; $index++) { $allowed[] = $this->line($visibleOrder, 'Доступная '.$index)->id; }
        $foreignLine = $this->line($foreignOrder, 'Чужая');
        $member = $fixture->addMember(['procurement' => ['procurement.purchase_orders.view'], 'contract-management' => ['contracts.view']]);
        $member->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $policy = app(AssistantDataAccessPolicy::class);
        $query = $policy->entityQuery($member, $fixture->organization->id, 'purchase_order_item');
        self::assertNotNull($query);
        self::assertSame([$allowed[0]], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertSame($allowed, $policy->entityQuery($member, $fixture->organization->id, 'purchase_order_item')->orderBy('id')->pluck('id')->all());
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'purchase_order_item', $foreignLine->id));
        $source = app(ProcurementBusinessRagSource::class);
        foreach ($allowed as $id) {
            $chunks = [...$source->collectEntity($fixture->organization->id, 'purchase_order_item', $id)];
            self::assertCount(1, $chunks);
            self::assertSame($visible->id, $chunks[0]->projectId);
            self::assertSame('25.00', $chunks[0]->metadata['total_price']);
        }
        $visibleOrder->delete();
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'purchase_order_item', $allowed[0]));
        self::assertSame([], [...$source->collectEntity($fixture->organization->id, 'purchase_order_item', $allowed[0])]);
    }

    public function test_crm_timeline_checks_polymorphic_parent_current_project_and_current_role_before_limit(): void
    {
        $fixture = $this->fixture();
        $hidden = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $visible = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $hiddenEvent = $this->timeline($fixture->organization, $hidden, 'Скрытая сделка');
        $visibleEvent = $this->timeline($fixture->organization, $visible, 'Доступная сделка');
        $member = $fixture->addMember(['crm' => ['crm.view', 'crm.deals.view', 'crm.companies.view', 'crm.timeline.view']]);
        $member->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'crm_timeline_event', $hiddenEvent->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'crm_timeline_event', $visibleEvent->id));
        $query = $policy->entityQuery($member, $fixture->organization->id, 'crm_timeline_event');
        self::assertNotNull($query);
        self::assertSame([$visibleEvent->id], $query->orderBy('created_at')->limit(1)->pluck('id')->all());
        UserRoleAssignment::query()->where('user_id', $member->id)->update(['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'crm_timeline_event', $visibleEvent->id));
        self::assertFalse($policy->canReadSource($member, $fixture->organization->id, ['entity_type' => 'crm_timeline_event', 'entity_id' => $visibleEvent->id]));
    }

    public function test_presale_declared_permissions_do_not_invent_a_current_canonical_read_entitlement(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        [$estimate, $version, $line] = Model::withoutEvents(function () use ($fixture, $project): array {
            $estimate = PresaleEstimate::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'number' => 'QA-'.Str::uuid(), 'title' => 'Предварительная смета']);
            $version = PresaleEstimateVersion::query()->create(['organization_id' => $fixture->organization->id, 'presale_estimate_id' => $estimate->id, 'version_number' => 1, 'title' => 'Версия 1']);
            $line = PresaleEstimateLineItem::query()->create(['organization_id' => $fixture->organization->id, 'presale_estimate_id' => $estimate->id, 'presale_estimate_version_id' => $version->id, 'title' => 'Бетон', 'quantity' => '2.5000', 'unit_cost' => '100.00', 'subtotal_amount' => '250.00', 'total_amount' => '250.00']);
            return [$estimate, $version, $line];
        });
        $member = $fixture->addMember(['presale-estimates' => ['presale_estimates.view', 'presale_estimates.amounts.view']]);
        $member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $modules = app(\App\Services\Entitlements\OrganizationEntitlementService::class)->getEffectiveModules($fixture->organization->id);
        self::assertFalse($modules->contains('slug', 'presale-estimates'));
        self::assertFalse(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($member, 'presale_estimates.view', ['organization_id' => $fixture->organization->id]));
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canReadEntity($member, $fixture->organization->id, 'presale_estimate_line_item', $line->id));
        $availability = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata::availabilityDefinitions()['presale_estimate_line_item'];
        self::assertSame('unavailable', $availability['availability']);
        self::assertSame('no_current_canonical_read_entitlement', $availability['reason_code']);
        $source = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PresaleBusinessRagSource::class);
        self::assertFalse($source->enabled());
        self::assertSame([], [...$source->collectEntity($fixture->organization->id, 'presale_estimate_line_item', $line->id)]);
    }

    public function test_marketplace_uses_the_real_paid_module_and_only_own_or_published_connected_profiles(): void
    {
        $fixture = $this->fixture();
        $outside = Organization::factory()->verified()->create();
        [$own, $connected, $unconnected] = Model::withoutEvents(function () use ($fixture, $outside): array {
            Contractor::query()->create(['organization_id' => $fixture->organization->id, 'source_organization_id' => $fixture->foreignOrganization->id, 'name' => 'Связанный подрядчик', 'contractor_type' => 'invited_organization']);
            $class = \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile::class;
            $own = $class::query()->create(['organization_id' => $fixture->organization->id, 'display_name' => 'Свой профиль', 'status' => 'draft', 'is_visible_in_marketplace' => false]);
            $connected = $class::query()->create(['organization_id' => $fixture->foreignOrganization->id, 'display_name' => 'Связанный профиль', 'status' => 'active', 'is_visible_in_marketplace' => true]);
            $unconnected = $class::query()->create(['organization_id' => $outside->id, 'display_name' => 'Чужой опубликованный профиль', 'status' => 'active', 'is_visible_in_marketplace' => true]);
            return [$own, $connected, $unconnected];
        });
        $member = $fixture->addMember(['contractor-portal' => ['contractor_marketplace.profile.view']]);
        $entitlements = app(\App\Services\Entitlements\OrganizationEntitlementService::class)->getEffectiveModules($fixture->organization->id);
        self::assertTrue($entitlements->contains('slug', 'contractor-portal'));
        self::assertFalse($entitlements->contains('slug', 'contractor-marketplace'));
        $authorization = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        self::assertTrue($authorization->canCurrent($member, 'contractor_marketplace.profile.view', ['organization_id' => $fixture->organization->id]));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'marketplace_contractor_profile', $own->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'marketplace_contractor_profile', $connected->id));
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'marketplace_contractor_profile', $unconnected->id));
        $connected->update(['is_visible_in_marketplace' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'marketplace_contractor_profile', $connected->id));
        UserRoleAssignment::query()->where('user_id', $member->id)->update(['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'marketplace_contractor_profile', $own->id));
    }

    public function test_policyless_reporting_snapshots_keep_organization_and_non_null_policy_scope(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['procurement' => ['procurement.dashboard.view', 'procurement.purchase_orders.view']]);
        $policy = app(AssistantDataAccessPolicy::class);
        $source = app(ProcurementBusinessRagSource::class);
        foreach (['procurement_cycle_snapshot', 'supply_reliability_snapshot'] as $type) {
            $definition = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata::recordDefinitions()[$type];
            $model = new $definition['model'];
            $foreignPolicy = $this->reportingPolicy($type, $fixture->foreignOrganization->id);
            $ownPolicy = $this->reportingPolicy($type, $fixture->organization->id);
            $ids = [];
            foreach ([[null, $fixture->organization->id], [$ownPolicy, $fixture->organization->id], [$foreignPolicy, $fixture->organization->id], [null, $fixture->foreignOrganization->id]] as [$parentId, $organizationId]) {
                $id = (string) Str::ulid();
                $hash = hash('sha256', $id);
                DB::table($model->getTable())->insert([
                    'id' => $id, 'organization_id' => $organizationId, 'definition_hash' => $hash, 'query_hash' => $hash,
                    'scope_hash' => $hash, 'source_hash' => $hash, 'formula_version' => 'v1', 'source_schema_version' => 'v1',
                    'policy_version_id' => $parentId, 'as_of' => now(), 'generated_at' => now(), 'row_count' => 0,
                    'eligible_count' => 0, $type === 'procurement_cycle_snapshot' ? 'sla_numerator' : 'otif_numerator' => 0,
                    'quality_status' => 'complete', 'reconciliation_status' => 'not_applicable', 'totals' => '{}']);
                $ids[] = $id;
            }
            foreach ([0, 1] as $index) {
                self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, $type, $ids[$index]), $type);
                self::assertCount(1, [...$source->collectEntity($fixture->organization->id, $type, $ids[$index])]);
            }
            foreach ([2, 3] as $index) {
                self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $ids[$index]), $type);
                self::assertSame([], [...$source->collectEntity($fixture->organization->id, $type, $ids[$index])]);
            }
        }
        UserRoleAssignment::query()->where('user_id', $member->id)->where('context_id', AuthorizationContext::getOrganizationContext($fixture->organization->id)->id)->update(['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'supply_reliability_snapshot', $ids[0]));
    }

    public function test_receipt_lifecycle_without_promise_uses_real_inventory_lineage_and_retains_foreign_and_current_project_denials(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $member = $fixture->addMember(['procurement' => ['procurement.purchase_orders.view'], 'contract-management' => ['contracts.view'],
            'catalog-management' => ['materials.view', 'measurement_units.view', 'suppliers.view'], 'basic-warehouse' => ['warehouse.view'], 'project-management' => ['projects.view']]);
        $member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $unpromised = $this->receiptEvent($fixture->organization, $project, false);
        $promised = $this->receiptEvent($fixture->organization, $project, true);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $foreign = $this->receiptEvent($fixture->foreignOrganization, $foreignProject, false);
        self::assertNull($unpromised->promise_version_id);
        self::assertNotNull($promised->promise_version_id);
        $policy = app(AssistantDataAccessPolicy::class);
        $source = app(ProcurementBusinessRagSource::class);
        foreach ([$unpromised, $promised] as $event) {
            self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'supply_lifecycle_event', $event->id));
            self::assertCount(1, [...$source->collectEntity($fixture->organization->id, 'supply_lifecycle_event', $event->id)]);
        }
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'supply_lifecycle_event', $foreign->id));
        self::assertSame([], [...$source->collectEntity($fixture->organization->id, 'supply_lifecycle_event', $foreign->id)]);
        $member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'purchase_order_promise_version', $promised->promise_version_id));
        foreach ([$unpromised, $promised] as $event) {
            self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'supply_lifecycle_event', $event->id));
        }
    }

    public function test_receipt_return_shared_scopes_keep_legacy_results_pagination_and_project_projection(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $ownEvent = $this->receiptEvent($fixture->organization, $project, true);
        $foreignEvent = $this->receiptEvent($fixture->foreignOrganization, $foreignProject, true);
        $lineClass = \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine::class;
        $ownLine = $lineClass::query()->where('purchase_order_item_id', $ownEvent->purchase_order_item_id)->firstOrFail();
        $foreignLine = $lineClass::query()->where('purchase_order_item_id', $foreignEvent->purchase_order_item_id)->firstOrFail();
        $create = static function ($line, int $actorId): int {
            $occurredAt = \Carbon\CarbonImmutable::now('UTC')->startOfSecond();
            $key = (string) Str::uuid();
            $movement = app(\App\BusinessModules\Features\Procurement\Services\PurchaseReceiptInventoryService::class)
                ->returnQuantity($line, '0.001', 'testing', $actorId, $occurredAt);
            $event = app(\App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder::class)
                ->returned($line, $movement->id, '0.001', 'testing', $occurredAt, $key);
            return DB::table('purchase_receipt_returns')->insertGetId([
                'organization_id' => $line->purchaseReceipt->organization_id, 'purchase_receipt_line_id' => $line->id,
                'warehouse_movement_id' => $movement->id, 'supply_lifecycle_event_id' => $event->id, 'source_type' => 'warehouse_movement',
                'source_id' => $movement->id, 'source_version' => 1, 'quantity' => '0.001', 'reason_code' => 'testing',
                'actor_id' => $actorId, 'occurred_at' => $occurredAt, 'idempotency_key' => $key, 'payload_fingerprint' => str_repeat('a', 64),
            ]);
        };
        $expected = [];
        for ($n = 0; $n < 65; $n++) {
            $create($foreignLine, $fixture->foreignOwner->id);
            $expected[] = $create($ownLine, $fixture->owner->id);
        }
        $source = new ProcurementBusinessRagSource;
        foreach (['supply_lifecycle_event', 'procurement_process_event'] as $type) {
            foreach ([null, $project->id, $foreignProject->id] as $projectId) {
                $legacy = $source::scopedQuery($type, $fixture->organization->id, $projectId, false, ['reference'])->orderBy('id')->pluck('id')->all();
                self::assertSame($legacy, $source::scopedQuery($type, $fixture->organization->id, $projectId)->lazyById(50)->pluck('id')->all());
            }
        }
        foreach ([null, $project->id, $foreignProject->id] as $projectId) {
            $legacy = $source::scopedQuery('purchase_receipt_return', $fixture->organization->id, $projectId, false, ['reference'])->orderBy('id')->pluck('id')->all();
            $query = $source::scopedQuery('purchase_receipt_return', $fixture->organization->id, $projectId);
            self::assertSame($projectId === $foreignProject->id ? [] : $expected, $legacy);
            self::assertSame($legacy, $query->lazyById(50)->pluck('id')->all());
        }
        foreach ([$expected[0], $expected[64]] as $id) {
            $chunks = [...$source->collectEntity($fixture->organization->id, 'purchase_receipt_return', $id)];
            self::assertCount(1, $chunks);
            self::assertSame($project->id, $chunks[0]->projectId);
        }
        self::assertSame([], [...$source->collectEntity($fixture->foreignOrganization->id, 'purchase_receipt_return', $expected[0])]);
    }

    private function receiptEvent(Organization $organization, Project $project, bool $withPromise): \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent
    {
        return Model::withoutEvents(function () use ($organization, $project, $withPromise) {
            $order = $this->order($organization, $project);
            if ($withPromise) {
                $actor = \App\Models\User::factory()->create(['current_organization_id' => $organization->id]);
                $siteRequest = \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::query()->create([
                    'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
                    'title' => 'Материалы для приёмки', 'status' => 'approved', 'priority' => 'medium',
                    'request_type' => 'material_request', 'material_name' => 'Материал приёмки', 'material_quantity' => 1, 'material_unit' => 'м3']);
                $purchaseRequest = \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::query()->create([
                    'organization_id' => $organization->id, 'site_request_id' => $siteRequest->id,
                    'request_number' => 'QA-'.Str::uuid(), 'status' => 'approved', 'budget_currency' => 'RUB']);
                $order->updateQuietly(['purchase_request_id' => $purchaseRequest->id]);
            }
            $warehouse = \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Склад приёмки',
                'code' => 'QA-'.Str::uuid(), 'warehouse_type' => 'project', 'is_active' => true]);
            $material = \App\Models\Material::query()->create(['organization_id' => $organization->id, 'name' => 'Материал приёмки', 'is_active' => true]);
            $order->updateQuietly(['metadata' => ['warehouse_id' => $warehouse->id]]);
            $item = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'material_id' => $material->id,
                'material_name' => 'Материал приёмки', 'quantity' => '2.500', 'unit' => 'м3', 'unit_price' => '10.00', 'total_price' => '25.00',
                'metadata' => ['unit_dimension' => 'volume', 'unit_conversion_version' => 'qa-v1', 'reporting_source_version' => 1,
                    'tax_basis' => 'without_tax', 'freight_basis' => 'included']]);
            if ($withPromise) {
                app(\App\BusinessModules\Features\Procurement\Reporting\Supply\Services\PurchaseOrderPromiseVersionRecorder::class)
                    ->captureOriginal($item, \Carbon\CarbonImmutable::now('UTC')->addDay());
            }
            $occurredAt = \Carbon\CarbonImmutable::now('UTC')->startOfSecond();
            $receipt = \App\BusinessModules\Features\Procurement\Models\PurchaseReceipt::query()->create([
                'organization_id' => $organization->id, 'purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id,
                'receipt_number' => 'QA-'.Str::uuid(), 'receipt_date' => $occurredAt->toDateString(), 'status' => 'posted']);
            $line = \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine::query()->create([
                'purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id, 'quantity_received' => '2.500', 'price' => '10.00', 'total_amount' => '25.00',
                'metadata' => ['reporting_source_version' => 1, 'reporting_posted_at' => $occurredAt->toIso8601String()]]);
            $batch = 'purchase-receipt-line:'.$line->id;
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::query()->create([
                'organization_id' => $organization->id, 'warehouse_id' => $warehouse->id, 'material_id' => $material->id,
                'available_quantity' => '2.500', 'unit_price' => '10.00', 'batch_number' => $batch, 'created_at' => $occurredAt]);
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::query()->create([
                'organization_id' => $organization->id, 'warehouse_id' => $warehouse->id, 'material_id' => $material->id,
                'project_id' => $project->id, 'movement_type' => 'receipt', 'quantity' => '2.500', 'movement_date' => $occurredAt,
                'operation_category' => 'procurement_receipt', 'metadata' => ['purchase_order_item_id' => $item->id, 'batch_number' => $batch, 'reporting_source_version' => 1,
                    'unit_dimension' => 'volume', 'unit_code' => 'м3', 'unit_conversion_version' => 'qa-v1']]);
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptInventoryLot::query()->create([
                'organization_id' => $organization->id, 'purchase_receipt_line_id' => $line->id, 'warehouse_balance_id' => $balance->id,
                'receipt_warehouse_movement_id' => $movement->id, 'original_quantity' => '2.500', 'unit_dimension' => 'volume', 'unit_code' => 'м3', 'conversion_version' => 'qa-v1']);
            return app(\App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder::class)->receipt($line, $occurredAt);
        });
    }

    private function reportingPolicy(string $type, int $organizationId): int
    {
        $hash = hash('sha256', (string) Str::uuid());
        if ($type === 'supply_reliability_snapshot') {
            return DB::table('supply_reliability_policy_versions')->insertGetId([
                'organization_id' => $organizationId, 'policy_version' => 1, 'on_time_cutoff_seconds' => 0,
                'quantity_tolerance' => '0', 'exclude_cancellation_before_send' => true, 'post_send_exclusion_reason_codes' => '[]',
                'effective_from' => now(), 'source_hash' => $hash]);
        }
        return DB::table('procurement_cycle_policy_versions')->insertGetId([
            'organization_id' => $organizationId, 'version_number' => 1, 'formula_version' => 'procurement-cycle.v1', 'source_schema_version' => '1.0.0',
            'event_schema_version' => 'procurement-process-events.v1', 'calendar_version' => 'procurement-business-calendar.v1', 'calendar_hash' => $hash, 'timezone' => 'UTC',
            'weekly_windows' => '[]', 'exceptions' => '[]', 'stage_sla_seconds' => '{}', 'total_sla_seconds' => 60,
            'terminal_cancellation_policy' => '{}', 'effective_from' => now(), 'canonical_hash' => $hash, 'published_at' => now(), 'created_at' => now()]);
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        return AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
    }

    private function order(Organization $organization, Project $project): PurchaseOrder
    {
        return Model::withoutEvents(function () use ($organization, $project): PurchaseOrder {
            $supplier = Supplier::query()->create(['organization_id' => $organization->id, 'name' => 'Поставщик QA']);
            $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Подрядчик QA']);
            $contract = Contract::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'QA-'.Str::uuid(), 'date' => '2026-09-29', 'status' => 'active', 'total_amount' => '200.00']);
            return PurchaseOrder::query()->create(['organization_id' => $organization->id, 'supplier_id' => $supplier->id, 'contract_id' => $contract->id, 'order_number' => 'QA-'.Str::uuid(), 'order_date' => '2026-09-29', 'total_amount' => '200.00']);
        });
    }

    private function line(PurchaseOrder $order, string $title): PurchaseOrderItem
    {
        return Model::withoutEvents(fn () => PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'material_name' => $title, 'quantity' => '2.500', 'unit' => 'м3', 'unit_price' => '10.00', 'total_price' => '25.00']));
    }

    private function timeline(Organization $organization, Project $project, string $title): CrmTimelineEvent
    {
        return Model::withoutEvents(function () use ($organization, $project, $title): CrmTimelineEvent {
            $company = CrmCompany::query()->create(['organization_id' => $organization->id, 'name' => 'Компания QA']);
            $deal = CrmDeal::query()->create(['organization_id' => $organization->id, 'company_id' => $company->id, 'project_id' => $project->id, 'title' => $title]);
            return CrmTimelineEvent::query()->create(['organization_id' => $organization->id, 'entity_type' => 'deals', 'entity_id' => $deal->id, 'event_type' => 'created', 'summary' => $title]);
        });
    }
}
