<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseRequestResource;
use App\BusinessModules\Features\Procurement\Http\Resources\SupplierRequestResource;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PurchaseRequestCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_request_list_batches_orders_across_parents_after_expiry_and_keeps_detail_payloads(): void
    {
        Event::fake();
        $this->freezeTime();

        $context = AdminApiTestContext::create();
        $ids = [];
        $expired = null;
        for ($index = 0; $index < 4; $index++) {
            $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
            $supplier = $this->createSupplierRequest($purchaseRequest, $index === 0 ? 'sent' : 'draft', $index === 0 ? now()->subMinute() : null);
            if ($index === 0) {
                $expired = $supplier;
            }
            PurchaseOrder::query()->create([
                'organization_id' => $context->organization->id,
                'purchase_request_id' => $purchaseRequest->id,
                'order_number' => 'PO-PAGE-'.$purchaseRequest->id,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'total_amount' => 100,
                'currency' => 'RUB',
            ]);
            $ids[] = $purchaseRequest->id;
        }
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $expected = [];
        foreach ($ids as $id) {
            $detail = $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/procurement/purchase-requests/{$id}");
            $detail->assertOk();
            $expected[] = $detail->json('data');
        }
        SupplierRequest::query()->whereKey($expired?->id)->update(['status' => 'sent']);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $list = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20&sort_by=id&sort_dir=asc');
            $parentQueries = count(array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/\\bfrom\\s+"?purchase_requests\\b/i', $query['query']) === 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $list->assertOk();
        $this->assertSame($ids, collect($list->json('data'))->pluck('id')->all());
        $this->assertLessThanOrEqual(6, $parentQueries);
        $this->assertDatabaseHas('supplier_requests', ['id' => $expired?->id, 'status' => 'expired']);
        foreach ($expected as $index => $payload) {
            $this->assertSame($payload, $list->json("data.{$index}"));
        }
    }

    public function test_pending_request_with_expiring_supplier_keeps_lazy_order_graph_and_original_payload(): void
    {
        Event::fake();
        $this->freezeTime();

        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::PENDING);
        $supplier = $this->createSupplierRequest($purchaseRequest, 'sent', now()->subMinute());
        PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-EXPIRY-'.$purchaseRequest->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $before = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}");
        $before->assertOk();
        SupplierRequest::query()->whereKey($supplier->id)->update(['status' => 'sent']);
        $list = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20');

        $list->assertOk();
        $this->assertSame($before->json('data'), $list->json('data.0'));
        $this->assertDatabaseHas('supplier_requests', ['id' => $supplier->id, 'status' => 'expired']);
    }

    public function test_raw_resource_collections_accept_keys_and_keep_default_fresh_supplier_state(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $ids = [];
        $supplierIds = [];
        for ($index = 0; $index < 2; $index++) {
            $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
            $supplierRequest = $this->createSupplierRequest($purchaseRequest);
            $ids[] = $purchaseRequest->id;
            $supplierIds[] = $supplierRequest->id;
        }
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $loaded = PurchaseRequest::query()->with(['supplierRequests', 'lines'])->whereIn('id', $ids)->orderBy('id')->get();
        $supplierLoaded = SupplierRequest::query()->whereIn('id', $supplierIds)->orderBy('id')->get();
        SupplierRequest::query()->whereIn('id', $supplierIds)->update(['status' => 'sent']);
        $request = \Illuminate\Http\Request::create('/api/v1/admin/procurement/purchase-requests', 'GET');
        $request->setUserResolver(fn () => $context->user);

        $data = PurchaseRequestResource::collection($loaded)->response($request)->getData(true);
        $this->assertCount(2, $data['data']);
        $this->assertSame('sent', $data['data'][0]['supplier_requests'][0]['status']);
        $this->assertSame('sent', $data['data'][1]['supplier_requests'][0]['status']);
        $supplierData = SupplierRequestResource::collection($supplierLoaded)->resolve($request);
        $this->assertCount(2, $supplierData);
        $this->assertSame('sent', $supplierData[0]['status']);
        $this->assertSame('sent', $supplierData[1]['status']);
    }

    public function test_purchase_request_list_batches_chain_relations_as_the_page_grows(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $ids = [];
        for ($index = 0; $index < 8; $index++) {
            $siteRequest = $this->createSiteRequest($context, $project);
            $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
            $purchaseRequest->update(['site_request_id' => $siteRequest->id]);
            $this->createSupplierRequest($purchaseRequest);
            $ids[] = $purchaseRequest->id;
        }
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $small = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=1&sort_by=id&sort_dir=desc');
            $smallQueries = DB::getQueryLog();
            DB::flushQueryLog();
            $large = $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=8&sort_by=id&sort_dir=desc');
            $largeQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $small->assertOk();
        $large->assertOk();
        $this->assertSame(array_reverse($ids), collect($large->json('data'))->pluck('id')->all());
        $this->assertLessThanOrEqual(count($smallQueries) + 1, count($largeQueries));
        $graphQueryCount = static fn (array $queries): int => count(array_filter(
            $queries,
            static fn (array $query): bool => preg_match('/\\b(?:from|join)\\s+"?(?:supplier_proposals|supplier_proposal_decisions|purchase_order_items|purchase_receipts|purchase_receipt_lines|material_deliveries|site_requests)\\b/i', $query['query']) === 1,
        ));
        $this->assertGreaterThan(0, $graphQueryCount($smallQueries));
        $this->assertSame($graphQueryCount($smallQueries), $graphQueryCount($largeQueries));
        $this->assertSame($small->json('data.0.workflow_summary'), $large->json('data.0.workflow_summary'));
        $this->assertSame($small->json('data.0.procurement_chain_summary'), $large->json('data.0.procurement_chain_summary'));
    }

    public function test_purchase_request_list_preserves_supplier_request_expiry_after_batch_loading(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
        $supplierRequest = $this->createSupplierRequest($purchaseRequest, 'sent', now()->subMinute());
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20');

        $response->assertOk();
        $response->assertJsonPath('data.0.workflow_summary.stage', 'approved_without_supplier_requests');
        $response->assertJsonPath('data.0.workflow_summary.next_action', 'create_supplier_request');
        $response->assertJsonPath('data.0.supplier_requests.0.status', 'expired');
        $this->assertDatabaseHas('supplier_requests', ['id' => $supplierRequest->id, 'status' => 'expired']);
    }

    public function test_purchase_request_list_and_show_preserve_compact_chain_and_organization_scope(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::PENDING);
        $this->createSupplierRequest($purchaseRequest);
        PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-HTTP-'.$purchaseRequest->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
        $draft = $this->createPurchaseRequest($context);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext, PurchaseRequestStatusEnum::PENDING);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $expectedSummary = app(ProcurementChainService::class)
            ->forPurchaseRequest($purchaseRequest, $context->user)
            ->compact()
            ->toArray();

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20&status=pending');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertSame([$purchaseRequest->id], $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($foreignPurchaseRequest->id, $ids);
        $this->assertSame($expectedSummary, $indexResponse->json('data.0.procurement_chain_summary'));
        $this->assertNotNull($indexResponse->json('data.0.workflow_summary'));

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $purchaseRequest->id);
        $this->assertSame($expectedSummary, $showResponse->json('data.procurement_chain_summary'));
        $this->assertSame($indexResponse->json('data.0.workflow_summary'), $showResponse->json('data.workflow_summary'));
        $this->assertSame($indexResponse->json('data.0'), $showResponse->json('data'));
        $this->assertArrayNotHasKey('current_version', $indexResponse->json('data.0.supplier_requests.0'));
        $this->assertArrayNotHasKey('lines', $indexResponse->json('data.0.supplier_requests.0'));

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$foreignPurchaseRequest->id}")
            ->assertNotFound();
    }

    public function test_owner_can_create_list_show_and_reject_purchase_request_without_organization_leaks(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $siteRequest = $this->createSiteRequest($context, $project);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Rebar A500', 'REB-PR');
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $assignee->id,
                'needed_by' => now()->addDays(4)->toDateString(),
                'budget_amount' => 125000.50,
                'budget_currency' => 'RUB',
                'notes' => 'Urgent delivery to site',
                'lines' => [
                    [
                        'material_id' => $material->id,
                        'name' => 'Rebar A500',
                        'quantity' => 12.5,
                        'unit' => 't',
                        'specification' => '12 mm bars',
                    ],
                    [
                        'name' => 'Binding wire',
                        'quantity' => 50,
                        'unit' => 'kg',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::PENDING->value);
        $createResponse->assertJsonPath('data.can_be_approved', true);
        $createResponse->assertJsonPath('data.site_request_id', $siteRequest->id);
        $createResponse->assertJsonPath('data.assigned_user.id', $assignee->id);
        $createResponse->assertJsonPath('data.lines.0.material_id', $material->id);
        $this->assertNotNull($createResponse->json('data.workflow_summary'));

        $purchaseRequest = PurchaseRequest::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $purchaseRequest->organization_id);
        $this->assertSame($siteRequest->id, $purchaseRequest->site_request_id);
        $this->assertSame($assignee->id, $purchaseRequest->assigned_to);
        $this->assertSame('125000.50', (string) $purchaseRequest->budget_amount);
        $this->assertSame(2, $purchaseRequest->lines()->count());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/purchase-requests?per_page=20&status=pending');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($purchaseRequest->id, $ids);
        $this->assertNotContains($foreignPurchaseRequest->id, $ids);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $purchaseRequest->id);
        $showResponse->assertJsonPath('data.site_request.project.id', $project->id);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/procurement/purchase-requests/{$foreignPurchaseRequest->id}");

        $foreignShowResponse->assertNotFound();

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/reject", [
                'reason' => 'Budget moved to next delivery batch',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::REJECTED->value);
        $this->assertStringContainsString(
            'Budget moved to next delivery batch',
            (string) $purchaseRequest->fresh()->notes
        );
    }

    public function test_purchase_request_creation_rejects_foreign_links_without_mutation(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $ownUnit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $ownMaterial = $this->createMaterial($context->organization->id, $ownUnit->id, 'Own cement', 'CEM-OWN');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-FOR');
        $ownProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignSiteRequest = $this->createSiteRequest($foreignContext, $foreignProject);
        $foreignAssignee = User::factory()->create(['current_organization_id' => $foreignContext->organization->id]);
        $foreignContext->organization->users()->attach($foreignAssignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $ownSiteRequest = $this->createSiteRequest($context, $ownProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignSiteResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $foreignSiteRequest->id,
                'lines' => [
                    [
                        'material_id' => $ownMaterial->id,
                        'name' => 'Own cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignSiteResponse->assertStatus(422);

        $foreignAssigneeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $ownSiteRequest->id,
                'assigned_to' => $foreignAssignee->id,
                'lines' => [
                    [
                        'material_id' => $ownMaterial->id,
                        'name' => 'Own cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignAssigneeResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/purchase-requests', [
                'site_request_id' => $ownSiteRequest->id,
                'lines' => [
                    [
                        'material_id' => $foreignMaterial->id,
                        'name' => 'Foreign cement',
                        'quantity' => 10,
                        'unit' => 'bag',
                    ],
                ],
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('purchase_requests', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('purchase_request_lines', [
            'material_id' => $foreignMaterial->id,
        ]);
    }

    public function test_purchase_request_creation_rejects_any_draft_site_request_before_writes(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($otherUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $ownDraft = $this->createSiteRequest($context, $project);
        $ownDraft->update(['status' => SiteRequestStatusEnum::DRAFT->value]);
        $foreignDraft = $this->createSiteRequest($context, $project);
        $foreignDraft->update([
            'user_id' => $otherUser->id,
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        foreach ([$ownDraft, $foreignDraft] as $draft) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/procurement/purchase-requests', [
                    'site_request_id' => $draft->id,
                    'lines' => [[
                        'name' => 'Draft material',
                        'quantity' => 1,
                        'unit' => 'pcs',
                    ]],
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseMissing('purchase_requests', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_pending_purchase_request_can_be_approved_once_and_foreign_request_is_hidden(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::PENDING);
        $foreignPurchaseRequest = $this->createPurchaseRequest($foreignContext, PurchaseRequestStatusEnum::PENDING);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve");

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', PurchaseRequestStatusEnum::APPROVED->value);
        $this->assertSame(PurchaseRequestStatusEnum::APPROVED, $purchaseRequest->fresh()->status);

        $secondApproveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve");

        $secondApproveResponse->assertStatus(422);
        $this->assertSame(PurchaseRequestStatusEnum::APPROVED, $purchaseRequest->fresh()->status);

        $foreignApproveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$foreignPurchaseRequest->id}/approve");

        $foreignApproveResponse->assertNotFound();
        $this->assertSame(PurchaseRequestStatusEnum::PENDING, $foreignPurchaseRequest->fresh()->status);
    }

    public function test_direct_purchase_order_creation_from_request_is_blocked_until_proposal_selection(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $purchaseRequest = $this->createPurchaseRequest($context, PurchaseRequestStatusEnum::APPROVED);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/create-order", [
                'supplier_id' => 1,
            ]);

        $response->assertStatus(410);
        $this->assertSame(0, PurchaseOrder::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->count());
    }

    private function createSiteRequest(AdminApiTestContext $context, Project $project): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Site material request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value,
            'priority' => 'medium',
            'material_name' => 'Cement',
            'material_quantity' => 10,
            'material_unit' => 'bag',
        ]);
    }

    private function createPurchaseRequest(
        AdminApiTestContext $context,
        PurchaseRequestStatusEnum $status = PurchaseRequestStatusEnum::DRAFT
    ): PurchaseRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $context->organization->id,
            'request_number' => 'PR-'.$context->organization->id.'-'.uniqid(),
            'status' => $status->value,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Existing line',
            'quantity' => 1,
            'unit' => 'pcs',
        ]);

        return $purchaseRequest;
    }

    private function createUnit(int $organizationId, string $name, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'short_name' => $shortName,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createSupplierRequest(PurchaseRequest $purchaseRequest, string $status = 'draft', ?\DateTimeInterface $expiresAt = null): SupplierRequest
    {
        return SupplierRequest::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-HTTP-'.$purchaseRequest->id,
            'status' => $status,
            'public_token' => 'test-'.$purchaseRequest->id.str_repeat('a', 40),
            'public_token_expires_at' => $expiresAt ?? now()->addDay(),
        ]);
    }

    private function createMaterial(int $organizationId, int $unitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $unitId,
            'category' => 'Procurement',
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'procurement',
                    'basic-warehouse',
                ], true));
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
