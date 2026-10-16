<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\Procurement\Http\Resources\MobilePurchaseRequestResource;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Http\Request;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class ProcurementMobileSummaryAuthorizationSecurityTest extends TestCase
{
    public function test_native_request_only_member_cannot_read_order_details_through_summary(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'supply-warehouse']);
        $member = $fixture->addMember(['procurement' => ['procurement.purchase_requests.view']]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id, 'status' => 'active', 'is_archived' => false]);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        [$purchaseRequest, $order] = $this->order($member, $project);
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => $fixture->organization->id];
        self::assertTrue($authorization->can($member, 'procurement.purchase_requests.view', $context));
        self::assertFalse($authorization->can($member, 'procurement.purchase_orders.view', $context));
        self::assertFalse($authorization->can($member, 'procurement.view', $context));
        self::assertFalse($fixture->foreignOrganization->users()->whereKey($member->id)->exists());
        $headers = $this->headers($member, $fixture->organization->id);
        $this->getJson('/api/v1/mobile/procurement/purchase-orders', $headers)->assertForbidden();
        $this->getJson('/api/v1/mobile/procurement/purchase-orders/'.$order->id, $headers)->assertForbidden();
        $this->getJson('/api/v1/mobile/procurement/purchase-requests', $headers)->assertOk()
            ->assertJsonPath('data.items.0.id', $purchaseRequest->id)
            ->assertJsonPath('data.items.0.budget_amount', 4000)
            ->assertJsonPath('data.items.0.lines.0.name', 'REQUEST-LINE-MARKER')
            ->assertJsonPath('data.items.0.purchase_orders', [])
            ->assertJsonPath('data.items.0.statistics.purchase_orders_count', 0);
        $this->getJson('/api/v1/mobile/procurement/purchase-requests/'.$purchaseRequest->id, $headers)->assertOk()
            ->assertJsonPath('data.id', $purchaseRequest->id)
            ->assertJsonPath('data.purchase_orders', [])
            ->assertJsonPath('data.statistics.purchase_orders_count', 0);
        $this->getJson('/api/v1/mobile/procurement/summary', $headers)->assertOk()
            ->assertJsonPath('data.purchase_requests.0.id', $purchaseRequest->id)
            ->assertJsonPath('data.purchase_requests.0.purchase_orders', [])
            ->assertJsonPath('data.purchase_requests.0.statistics.purchase_orders_count', 0)
            ->assertJsonPath('data.summary.purchase_requests_count', 1)
            ->assertJsonPath('data.summary.purchase_orders_count', 0)
            ->assertJsonPath('data.summary.receivable_orders_count', 0)
            ->assertJsonPath('data.purchase_orders', [])
            ->assertJsonPath('data.assigned_approvals', []);
    }

    public function test_native_summary_sections_match_order_umbrella_and_approval_permissions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'supply-warehouse']);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id, 'status' => 'active', 'is_archived' => false]);
        [$purchaseRequest, $order] = $this->order($fixture->owner, $project);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        [, $foreignOrder] = $this->order($fixture->foreignOwner, $foreignProject);
        foreach (['procurement.purchase_orders.view', 'procurement.view', 'procurement.approvals.view', null] as $permission) {
            $member = $fixture->addMember($permission === null ? [] : ['procurement' => [$permission]]);
            $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
            $headers = $this->headers($member, $fixture->organization->id);
            $response = $this->getJson('/api/v1/mobile/procurement/summary', $headers);
            if ($permission === null) {
                $response->assertForbidden();

                continue;
            }
            $canViewOrders = $permission !== 'procurement.approvals.view';
            $canViewRequests = $permission === 'procurement.view';
            $response->assertOk()
                ->assertJsonPath('data.summary.purchase_requests_count', $canViewRequests ? 1 : 0)
                ->assertJsonPath('data.summary.pending_requests_count', 0)
                ->assertJsonPath('data.summary.purchase_orders_count', $canViewOrders ? 1 : 0)
                ->assertJsonPath('data.summary.receivable_orders_count', $canViewOrders ? 1 : 0)
                ->assertJsonPath('data.assigned_approvals', [])
                ->assertJsonMissing(['id' => $foreignOrder->id, 'order_number' => $foreignOrder->order_number]);
            if ($canViewOrders) {
                $response->assertJsonPath('data.purchase_orders.0.id', $order->id)
                    ->assertJsonPath('data.purchase_orders.0.notes', 'ORDER-PRIVATE-MARKER')
                    ->assertJsonPath('data.purchase_orders.0.items.0.material_name', 'PRIVATE-ITEM-MARKER')
                    ->assertJsonPath('data.purchase_orders.0.supplier.label', 'PRIVATE-SUPPLIER-MARKER')
                    ->assertJsonStructure(['data' => ['purchase_orders' => [['payment_summary' => ['paid_amount', 'received_amount']]]]]);
                $this->getJson('/api/v1/mobile/procurement/purchase-orders/'.$order->id, $headers)->assertOk();
            } else {
                $response->assertJsonPath('data.purchase_orders', []);
                $this->getJson('/api/v1/mobile/procurement/purchase-orders/'.$order->id, $headers)->assertForbidden();
            }
            if ($canViewRequests) {
                $response->assertJsonPath('data.purchase_requests.0.id', $purchaseRequest->id)
                    ->assertJsonPath('data.purchase_requests.0.purchase_orders.0.id', $order->id)
                    ->assertJsonPath('data.purchase_requests.0.purchase_orders.0.total_amount', 400)
                    ->assertJsonPath('data.purchase_requests.0.statistics.purchase_orders_count', 1);
            } else {
                $response->assertJsonPath('data.purchase_requests', []);
                $this->getJson('/api/v1/mobile/procurement/purchase-requests/'.$purchaseRequest->id, $headers)->assertForbidden();
            }
        }
    }

    public function test_request_resource_hides_nested_orders_without_an_authenticated_user(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'supply-warehouse']);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        [$purchaseRequest] = $this->order($fixture->owner, $project);
        $request = new Request;
        $request->setUserResolver(static fn () => null);
        $data = (new MobilePurchaseRequestResource($purchaseRequest->load(['siteRequest.project', 'assignedUser', 'lines', 'purchaseOrders'])))->resolve($request);
        self::assertSame([], $data['purchase_orders']);
        self::assertSame(0, $data['statistics']['purchase_orders_count']);
        self::assertSame($purchaseRequest->id, $data['id']);
    }

    public function test_assigned_approvals_require_view_and_resolve_and_preserve_duty_separation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'supply-warehouse']);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        [$purchaseRequest] = $this->order($fixture->owner, $project);
        $supplierRequest = SupplierRequest::create([
            'organization_id' => $fixture->organization->id, 'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-NATIVE', 'status' => 'responded',
        ]);
        $proposal = SupplierProposal::create([
            'organization_id' => $fixture->organization->id, 'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-NATIVE', 'proposal_date' => now()->toDateString(), 'status' => 'submitted',
            'supplier_snapshot' => ['display_name' => 'Approval supplier'], 'subtotal_amount' => 1200,
            'delivery_amount' => 0, 'vat_amount' => 0, 'total_amount' => 1200, 'currency' => 'RUB',
        ]);
        $decision = SupplierProposalDecision::create([
            'organization_id' => $fixture->organization->id, 'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id, 'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'approval_required', 'is_lowest_price_selected' => true, 'comparison_snapshot' => [],
            'selected_at' => now(), 'selected_by' => $fixture->owner->id,
        ]);
        $approval = ProcurementApproval::create([
            'organization_id' => $fixture->organization->id, 'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id, 'reason_code' => 'budget_exceeded', 'status' => 'pending',
            'requested_by' => $fixture->owner->id, 'requested_at' => now(),
            'context' => ['selected_total' => 1200, 'budget_amount' => 1000, 'currency' => 'RUB'],
        ]);
        foreach ([['procurement.approvals.view'], ['procurement.purchase_requests.view', 'procurement.approvals.resolve'], ['procurement.approvals.view', 'procurement.approvals.resolve']] as $permissions) {
            $member = $fixture->addMember(['procurement' => $permissions]);
            $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
            $headers = $this->headers($member, $fixture->organization->id);
            $canSeeAssigned = in_array('procurement.approvals.view', $permissions, true) && in_array('procurement.approvals.resolve', $permissions, true);
            $response = $this->getJson('/api/v1/mobile/procurement/summary', $headers)->assertOk()
                ->assertJsonPath('data.summary.pending_approvals_count', $canSeeAssigned ? 1 : 0)
                ->assertJsonPath('data.purchase_orders', []);
            if (! $canSeeAssigned) {
                $response->assertJsonPath('data.assigned_approvals', []);

                continue;
            }
            $response->assertJsonPath('data.assigned_approvals.0.id', $approval->id)
                ->assertJsonPath('data.assigned_approvals.0.can_resolve', true)
                ->assertJsonPath('data.assigned_approvals.0.context_summary.selected_total', 1200);
            $approval->update(['requested_by' => $member->id]);
            $this->getJson('/api/v1/mobile/procurement/summary', $headers)->assertOk()
                ->assertJsonPath('data.summary.pending_approvals_count', 0)
                ->assertJsonPath('data.assigned_approvals', []);
        }
    }

    private function headers(User $user, int $organizationId): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.app(JwtTokenIssuer::class)->issue($user, ['guard' => 'api_mobile', 'organization_id' => $organizationId]), 'Accept' => 'application/json'];
    }

    private function order(User $user, Project $project): array
    {
        $siteRequest = SiteRequest::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'user_id' => $user->id,
            'title' => 'Native procurement request', 'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value, 'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete', 'material_quantity' => 5, 'material_unit' => 'м3',
        ]);
        $purchaseRequest = PurchaseRequest::create([
            'organization_id' => $project->organization_id, 'site_request_id' => $siteRequest->id, 'assigned_to' => $user->id,
            'request_number' => 'PR-NATIVE-'.$siteRequest->id, 'status' => 'approved', 'needed_by' => now()->addDays(2)->toDateString(),
            'budget_amount' => 4000, 'budget_currency' => 'RUB',
        ]);
        $order = PurchaseOrder::create([
            'organization_id' => $project->organization_id, 'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-NATIVE-'.$purchaseRequest->id, 'order_date' => now()->toDateString(), 'status' => 'confirmed',
            'supplier_snapshot' => ['display_name' => 'PRIVATE-SUPPLIER-MARKER'], 'total_amount' => 400, 'currency' => 'RUB', 'notes' => 'ORDER-PRIVATE-MARKER',
        ]);
        $unit = MeasurementUnit::create(['organization_id' => $project->organization_id, 'name' => 'Cubic metre', 'short_name' => 'м3', 'type' => 'material']);
        $material = Material::create(['organization_id' => $project->organization_id, 'name' => 'PRIVATE-ITEM-MARKER', 'code' => 'NATIVE-'.$order->id, 'measurement_unit_id' => $unit->id, 'default_price' => 80, 'is_active' => true]);
        PurchaseRequestLine::create(['purchase_request_id' => $purchaseRequest->id, 'material_id' => $material->id, 'name' => 'REQUEST-LINE-MARKER', 'quantity' => 5, 'unit' => 'м3', 'estimated_price' => 80]);
        PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'material_id' => $material->id, 'material_name' => $material->name, 'quantity' => 5, 'unit' => 'м3', 'unit_price' => 80, 'total_price' => 400]);

        return [$purchaseRequest, $order];
    }
}
