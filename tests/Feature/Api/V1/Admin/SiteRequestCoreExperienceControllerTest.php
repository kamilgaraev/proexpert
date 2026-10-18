<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestGroup;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
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

class SiteRequestCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_cost_is_applied_without_losing_explicit_overrides(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $template = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests/templates', [
                'name' => 'Training cost template',
                'request_type' => 'other',
                'template_data' => [
                    'title' => 'Training request from template',
                    'request_type' => 'other',
                    'location' => 'Training office',
                    'metadata' => ['estimated_cost' => 120.50, 'training' => true],
                ],
                'is_active' => true,
            ])->assertCreated()->json('data.id');

        foreach ([
            [[], 120.5, 120.5],
            [['estimated_cost' => 0], 0, 120.5],
            [['estimated_cost' => null], null, 120.5],
            [['metadata' => ['estimated_cost' => 0, 'training' => true]], 0, 0],
        ] as [$overrides, $expectedCost, $expectedMetadataCost]) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/site-requests/templates/{$template}/create", [
                    'project_id' => $project->id,
                    ...$overrides,
                ])->assertCreated()
                ->assertJsonPath('data.estimated_cost', $expectedCost)
                ->assertJsonPath('data.location', 'Training office');
            $created = SiteRequest::query()->findOrFail($response->json('data.id'));
            $this->assertSame($expectedMetadataCost, $created->metadata['estimated_cost']);
            $this->assertTrue($created->metadata['training']);
            $this->assertDatabaseHas('site_requests', [
                'id' => $created->id,
                'project_id' => $project->id,
                'estimated_cost' => $expectedCost,
            ]);
        }
        $this->assertDatabaseHas('site_request_templates', ['id' => $template, 'usage_count' => 4]);
    }

    public function test_template_rejects_invalid_inherited_or_overridden_cost_without_creating_requests(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $template = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests/templates', [
                'name' => 'Invalid training cost template',
                'request_type' => 'other',
                'template_data' => [
                    'title' => 'Training request with invalid cost',
                    'request_type' => 'other',
                    'metadata' => ['estimated_cost' => 'invalid'],
                ],
            ])->assertCreated()->json('data.id');
        $requestCount = SiteRequest::query()->count();
        foreach ([
            [],
            ['metadata' => ['estimated_cost' => -1]],
            ['metadata' => ['estimated_cost' => '1000000000000']],
            ['metadata' => ['estimated_cost' => '1.234']],
            ['estimated_cost' => -1],
            ['estimated_cost' => 'invalid'],
        ] as $overrides) {
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/site-requests/templates/{$template}/create", [
                    'project_id' => $project->id,
                    ...$overrides,
                ])->assertUnprocessable();
            $this->assertDatabaseCount('site_requests', $requestCount);
            $this->assertDatabaseHas('site_request_templates', ['id' => $template, 'usage_count' => 0]);
        }
    }

    public function test_owner_can_create_material_request_group_and_list_only_own_requests(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Concrete mix', 'CONC-SR');
        $foreignRequest = $this->createSiteRequest($foreignContext, Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]));
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $project->id,
                'title' => 'Pour slab materials',
                'description' => 'Materials for morning slab pour',
                'location' => 'Office, first floor',
                'estimated_cost' => 30300.25,
                'requester_comment' => 'Training supply request',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'priority' => 'high',
                'required_date' => now()->addDays(2)->toDateString(),
                'delivery_address' => 'Moscow, Test site',
                'delivery_time_from' => '09:00',
                'delivery_time_to' => '12:00',
                'materials' => [
                    [
                        'material_id' => $material->id,
                        'quantity' => 7.5,
                        'unit' => 'm3',
                        'note' => 'Foundation zone A',
                    ],
                    [
                        'name' => 'Fiber additive',
                        'quantity' => 10,
                        'unit' => 'kg',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.title', 'Pour slab materials');
        $createResponse->assertJsonPath('data.location', 'Office, first floor')
            ->assertJsonPath('data.estimated_cost', 30300.25)
            ->assertJsonPath('data.requester_comment', 'Training supply request');

        $group = SiteRequestGroup::query()
            ->where('organization_id', $context->organization->id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $requests = SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $group->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $requests);
        $this->assertSame('30300.25', $group->estimated_cost);
        foreach ($requests as $request) {
            $this->assertSame('Office, first floor', $request->location);
            $this->assertSame('Training supply request', $request->requester_comment);
            $this->assertNull($request->estimated_cost);
        }
        $this->assertSame(SiteRequestStatusEnum::DRAFT, $requests[0]->status);
        $this->assertSame($material->id, $requests[0]->material_id);
        $this->assertSame('Concrete mix', $requests[0]->material_name);
        $this->assertSame('Fiber additive', $requests[1]->material_name);
        $this->assertDatabaseHas('site_request_history', [
            'site_request_id' => $requests[0]->id,
            'user_id' => $context->user->id,
            'action' => 'created',
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/site-requests?per_page=20&request_type=material_request');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($requests[0]->id, $ids);
        $this->assertContains($requests[1]->id, $ids);
        $this->assertNotContains($foreignRequest->id, $ids);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$group->id}", [
                'estimated_cost' => 0, 'location' => 'Office, second floor', 'requester_comment' => 'Updated context',
            ])->assertOk()->assertJsonPath('data.estimated_cost', 0)
            ->assertJsonPath('data.location', 'Office, second floor')
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.project.name', $project->name)
            ->assertJsonPath('data.requests.0.project.id', $project->id)
            ->assertJsonPath('data.requests.0.project.name', $project->name)
            ->assertJsonPath('data.requests.0.user.id', $context->user->id)
            ->assertJsonPath('data.requests.1.project.id', $project->id)
            ->assertJsonPath('data.requests.1.project.name', $project->name);
        foreach ($requests as $request) {
            $request->refresh();
            $this->assertSame('Office, second floor', $request->location);
            $this->assertSame('Updated context', $request->requester_comment);
            $this->assertNull($request->estimated_cost);
        }
        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$group->id}", [
                'description' => null, 'estimated_cost' => null, 'location' => null, 'requester_comment' => null,
            ])->assertOk()->assertJsonPath('data.estimated_cost', null);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/site-requests/groups/{$group->id}")
            ->assertOk()->assertJsonPath('data.location', null)->assertJsonPath('data.requester_comment', null)
            ->assertJsonPath('data.description', null);
        foreach ($requests as $request) {
            $this->assertNull($request->fresh()->location);
            $this->assertNull($request->fresh()->requester_comment);
        }
    }

    public function test_single_request_context_is_stored_and_historical_date_can_be_edited(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['is_active' => true]);
        $this->allowAdminAccess(modulePermissions: ['site_requests.assign']);
        $this->allowModuleAccess();
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/site-requests', [
            'project_id' => $project->id, 'title' => 'Training request', 'request_type' => 'other',
            'location' => 'Office', 'estimated_cost' => 0, 'requester_comment' => 'No payment',
        ])->assertCreated()->assertJsonPath('data.estimated_cost', 0);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('site_requests', ['id' => $id, 'location' => 'Office', 'requester_comment' => 'No payment', 'estimated_cost' => 0]);
        $this->withHeaders($context->authHeaders())->putJson("/api/v1/admin/site-requests/{$id}", [
            'required_date' => '2020-01-01', 'estimated_cost' => 42.50, 'location' => 'Floor 2', 'requester_comment' => null,
        ])->assertOk()->assertJsonPath('data.required_date', '2020-01-01')->assertJsonPath('data.estimated_cost', 42.5);
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/site-requests/{$id}/assign", [
            'user_id' => $context->user->id,
        ])->assertOk();
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/site-requests/{$id}/status", [
            'status' => 'pending', 'notes' => 'Training review comment',
        ])->assertOk();
        $detail = $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/site-requests/{$id}")
            ->assertOk()->assertJsonPath('data.location', 'Floor 2')->assertJsonPath('data.requester_comment', null)
            ->assertJsonCount(4, 'data.history')
            ->assertJsonStructure(['data' => ['history' => [['id', 'site_request_id', 'user_id', 'action', 'old_values', 'new_values', 'comment', 'created_at', 'user' => ['id', 'name']]]]]);
        $history = collect($detail->json('data.history'))->keyBy('action');
        $this->assertSame('Training request', $history['created']['new_values']['title']);
        $this->assertNull($history['created']['old_values']);
        $this->assertSame('Office', $history['updated']['old_values']['location']);
        $this->assertSame('Floor 2', $history['updated']['new_values']['location']);
        $this->assertNull($history['assigned']['old_values']['assigned_to']);
        $this->assertSame($context->user->id, $history['assigned']['new_values']['assigned_to']);
        $this->assertSame('draft', $history['status_changed']['old_values']['status']);
        $this->assertSame('pending', $history['status_changed']['new_values']['status']);
        $this->assertSame('Training review comment', $history['status_changed']['comment']);
        foreach ($history as $entry) {
            $this->assertSame($context->user->id, $entry['user']['id']);
            $this->assertSame($context->user->name, $entry['user']['name']);
            $this->assertNotEmpty($entry['created_at']);
            $this->assertArrayNotHasKey('email', $entry['user']);
        }
    }

    public function test_invalid_costs_and_past_creation_date_are_rejected_without_mutation(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $payload = ['project_id' => $project->id, 'title' => 'Training request', 'request_type' => 'other'];
        foreach ([-1, 1.001, 1000000000000] as $cost) {
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/site-requests', [...$payload, 'estimated_cost' => $cost])
                ->assertUnprocessable()->assertJsonValidationErrors('estimated_cost');
        }
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/site-requests', [...$payload, 'required_date' => '2020-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('required_date');
        $this->assertStringNotContainsString('today', $response->getContent());
        $this->assertSame(trans_message('site_requests.required_date_not_past'), $response->json('errors.required_date.0'));
        $this->assertDatabaseMissing('site_requests', ['organization_id' => $context->organization->id]);
        $this->assertDatabaseMissing('site_request_groups', ['organization_id' => $context->organization->id]);
    }

    public function test_site_request_creation_rejects_foreign_project_and_material_without_mutation(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $ownProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $foreignMaterial = $this->createMaterial(
            $foreignContext->organization->id,
            $foreignUnit->id,
            'Foreign concrete',
            'CONC-F'
        );
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project request',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'material_name' => 'Concrete',
                'material_quantity' => 3,
                'material_unit' => 'm3',
            ]);

        $foreignProjectResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $ownProject->id,
                'title' => 'Foreign material request',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'material_id' => $foreignMaterial->id,
                'material_quantity' => 3,
                'material_unit' => 'm3',
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('site_requests', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('site_request_groups', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_group_update_rejects_foreign_request_ids_without_replacing_group_items(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Rebar', 'REB-SR');
        $ownGroup = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Rebar pack',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $ownRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $ownGroup->id,
            'title' => 'Rebar pack - A500',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'medium',
            'material_id' => $material->id,
            'material_name' => 'Rebar A500',
            'material_quantity' => 2,
            'material_unit' => 't',
        ]);
        $foreignRequest = $this->createSiteRequest($foreignContext, $foreignProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$ownGroup->id}", [
                'title' => 'Rebar pack updated',
                'materials' => [
                    [
                        'id' => $foreignRequest->id,
                        'material_id' => $material->id,
                        'quantity' => 4,
                        'unit' => 't',
                    ],
                ],
            ]);

        $updateResponse->assertStatus(422);

        $this->assertDatabaseHas('site_requests', [
            'id' => $ownRequest->id,
            'organization_id' => $context->organization->id,
            'site_request_group_id' => $ownGroup->id,
            'material_quantity' => 2,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $ownGroup->id)
            ->count());
    }

    public function test_group_update_can_add_catalog_material_to_empty_group_with_default_priority(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Dry mix', 'DRY-MIX');
        $emptyGroup = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Additional materials',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$emptyGroup->id}", [
                'materials' => [
                    [
                        'material_id' => $material->id,
                        'quantity' => 12,
                        'unit' => 'pcs',
                        'note' => 'Add from catalog',
                    ],
                ],
            ]);

        $response->assertOk();

        $createdRequest = SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $emptyGroup->id)
            ->firstOrFail();

        $this->assertSame(SiteRequestPriorityEnum::MEDIUM, $createdRequest->priority);
        $this->assertSame($material->id, $createdRequest->material_id);
        $this->assertSame('Dry mix', $createdRequest->material_name);
        $this->assertSame('12.000', (string) $createdRequest->material_quantity);
        $this->assertSame('pcs', $createdRequest->material_unit);
    }

    public function test_group_detail_and_submit_are_scoped_and_transition_all_draft_requests(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $group = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Concrete delivery',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $requestA = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $group->id,
            'title' => 'Concrete delivery - mix',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'medium',
            'material_name' => 'Concrete mix',
            'material_quantity' => 5,
            'material_unit' => 'm3',
        ]);
        $requestB = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $group->id,
            'title' => 'Concrete delivery - additive',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'high',
            'material_name' => 'Additive',
            'material_quantity' => 2,
            'material_unit' => 'kg',
        ]);
        $foreignGroup = SiteRequestGroup::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'user_id' => $foreignContext->user->id,
            'title' => 'Foreign group',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $detailResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests/groups/{$group->id}");

        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('success', true);
        $detailResponse->assertJsonPath('data.id', $group->id);
        $detailResponse->assertJsonPath('data.requests.0.id', $requestA->id);
        $detailResponse->assertJsonPath('data.requests.1.id', $requestB->id);

        $foreignDetailResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests/groups/{$foreignGroup->id}");

        $foreignDetailResponse->assertNotFound();

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/groups/{$group->id}/submit");

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('success', true);
        $submitResponse->assertJsonPath('data.status', SiteRequestStatusEnum::PENDING->value);
        $submitResponse->assertJsonPath('data.project.id', $project->id);
        $submitResponse->assertJsonPath('data.project.name', $project->name);
        $submitResponse->assertJsonPath('data.requests.0.status', SiteRequestStatusEnum::PENDING->value);
        $submitResponse->assertJsonPath('data.requests.0.project.id', $project->id);
        $submitResponse->assertJsonPath('data.requests.0.project.name', $project->name);
        $submitResponse->assertJsonPath('data.requests.0.user.id', $context->user->id);
        $submitResponse->assertJsonPath('data.requests.1.status', SiteRequestStatusEnum::PENDING->value);
        $submitResponse->assertJsonPath('data.requests.1.project.id', $project->id);
        $submitResponse->assertJsonPath('data.requests.1.project.name', $project->name);
        $submitResponse->assertJsonPath('data.requests.1.user.id', $context->user->id);

        $this->assertDatabaseHas('site_request_groups', [
            'id' => $group->id,
            'organization_id' => $context->organization->id,
            'status' => SiteRequestStatusEnum::PENDING->value,
        ]);
        $this->assertDatabaseHas('site_requests', [
            'id' => $requestA->id,
            'organization_id' => $context->organization->id,
            'status' => SiteRequestStatusEnum::PENDING->value,
        ]);
        $this->assertDatabaseHas('site_requests', [
            'id' => $requestB->id,
            'organization_id' => $context->organization->id,
            'status' => SiteRequestStatusEnum::PENDING->value,
        ]);

        foreach ([$requestA, $requestB] as $submittedRequest) {
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/site-requests/{$submittedRequest->id}")
                ->assertOk()
                ->assertJsonCount(1, 'data.history')
                ->assertJsonPath('data.history.0.action', 'status_changed')
                ->assertJsonPath('data.history.0.site_request_id', $submittedRequest->id)
                ->assertJsonPath('data.history.0.old_values.status', 'draft')
                ->assertJsonPath('data.history.0.new_values.status', 'pending')
                ->assertJsonPath('data.history.0.comment', null)
                ->assertJsonPath('data.history.0.user.id', $context->user->id)
                ->assertJsonPath('data.history.0.user.name', $context->user->name);
        }

        $resubmitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/groups/{$group->id}/submit");

        $resubmitResponse->assertStatus(422);
    }

    public function test_empty_group_cannot_be_submitted(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $emptyGroup = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Empty group',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/groups/{$emptyGroup->id}/submit");

        $response->assertStatus(422);
        $this->assertDatabaseHas('site_request_groups', [
            'id' => $emptyGroup->id,
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
    }

    public function test_request_list_batches_procurement_and_payment_presence(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreign = AdminApiTestContext::create();
        $foreignRequest = $this->createSiteRequest($foreign, Project::factory()->create(['organization_id' => $foreign->organization->id]));
        $otherCreator = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $otherDraft = $this->createSiteRequest($context, $project);
        $otherDraft->update(['user_id' => $otherCreator->id]);
        $ids = [];

        for ($number = 1; $number <= 8; $number++) {
            $siteRequest = $this->createSiteRequest($context, $project);
            $siteRequest->update(['status' => SiteRequestStatusEnum::APPROVED]);
            $ids[] = $siteRequest->id;
            PurchaseRequest::query()->create([
                'organization_id' => $context->organization->id,
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $context->user->id,
                'request_number' => 'PAGE-PR-' . $number,
                'status' => 'pending',
            ]);
        }

        $checkedPermissions = [];
        $approvalChecks = 0;
        $this->allowAdminAccess(function (string $permission) use (&$checkedPermissions, &$approvalChecks): bool {
            $checkedPermissions[] = $permission;

            return $permission !== 'procurement.purchase_requests.approve' || ++$approvalChecks % 2 === 1;
        });
        $this->allowModuleAccess();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/site-requests?per_page=20');
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()->assertJsonCount(8, 'data.data');
        $returnedIds = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing($ids, $returnedIds);
        $this->assertNotContains($foreignRequest->id, $returnedIds);
        $this->assertNotContains($otherDraft->id, $returnedIds);
        $this->assertNotContains('procurement.supplier_proposals.accept', $checkedPermissions);

        foreach ($response->json('data.data') as $payload) {
            $this->assertFalse($payload['has_payment']);
            $this->assertTrue($payload['can_create_payment']);
            $this->assertIsArray($payload['action_summary']);
            $this->assertIsArray($payload['procurement_chain_summary']);
            $this->assertArrayNotHasKey('permissions', $payload['procurement_chain_summary']);
            $this->assertSame('approve_purchase_request', $payload['action_summary']['primary_action']['key']);
            $this->assertFalse($payload['action_summary']['primary_action']['is_enabled']);
        }

        $this->assertSame(16, $approvalChecks);

        $purchaseQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "purchase_requests"'));
        $this->assertCount(1, $purchaseQueries, $purchaseQueries->implode("\n"));
        $paymentQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "payment_documents"'));
        $this->assertCount(1, $paymentQueries, $paymentQueries->implode("\n"));
    }

    public function test_operator_can_close_material_request_without_procurement_with_audited_reason(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $request = $this->createSiteRequest($context, $project);
        $request->update(['status' => SiteRequestStatusEnum::APPROVED]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $reason = 'Материалы уже есть на объекте';

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$request->id}/status", [
                'status' => 'completed', 'close_without_procurement' => true, 'notes' => $reason,
            ])->assertOk()->assertJsonPath('data.status', 'completed');

        $request->refresh();
        $this->assertSame($reason, $request->metadata['closure_without_procurement']['reason']);
        $this->assertSame($context->user->id, $request->metadata['closure_without_procurement']['closed_by']);
        $this->assertDatabaseHas('site_request_history', [
            'site_request_id' => $request->id, 'user_id' => $context->user->id,
            'action' => 'status_changed', 'notes' => $reason,
        ]);
        $this->assertDatabaseMissing('purchase_requests', ['site_request_id' => $request->id]);
    }

    public function test_closing_without_procurement_requires_reason_and_rejects_linked_procurement(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $request = $this->createSiteRequest($context, $project);
        $request->update(['status' => SiteRequestStatusEnum::APPROVED]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$request->id}/status", [
                'status' => 'completed', 'close_without_procurement' => true,
            ])->assertUnprocessable();

        PurchaseRequest::query()->create([
            'organization_id' => $context->organization->id, 'site_request_id' => $request->id,
            'request_number' => 'PR-CLOSURE-TEST', 'status' => 'pending',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/site-requests/{$request->id}/status", [
                'status' => 'completed', 'close_without_procurement' => true, 'notes' => 'Не требуется',
            ])->assertUnprocessable();
        $this->assertSame(SiteRequestStatusEnum::APPROVED, $request->fresh()->status);
        $this->assertDatabaseMissing('site_request_history', ['site_request_id' => $request->id, 'action' => 'status_changed']);
    }

    public function test_linked_payment_still_blocks_closure_with_cached_presence_and_fresh_request(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $request = $this->createSiteRequest($context, $project);
        $request->update(['status' => SiteRequestStatusEnum::APPROVED]);
        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'PAY-CLOSURE-'.$request->id,
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $context->organization->id,
            'amount' => 100, 'currency' => 'RUB', 'paid_amount' => 0, 'remaining_amount' => 100,
            'status' => PaymentDocumentStatus::DRAFT,
        ]);
        $request->paymentDocuments()->attach($document->id, ['amount' => 100]);
        $closure = app(\App\BusinessModules\Features\SiteRequests\Services\SiteRequestClosureService::class);
        $this->assertFalse($closure->isAvailable($request->fresh()));
        $cachedRequest = SiteRequest::query()->withExists('paymentDocuments')->findOrFail($request->id);
        $this->assertFalse($closure->isAvailable($cachedRequest));
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/site-requests/{$request->id}/status", [
            'status' => 'completed', 'close_without_procurement' => true, 'notes' => 'Материалы на объекте',
        ])->assertUnprocessable();
        $this->assertSame(SiteRequestStatusEnum::APPROVED, $request->fresh()->status);
        $document->delete();
        $this->assertTrue($closure->isAvailable($request->fresh()));
        $request->update(['payment_document_id' => $document->id]);
        $this->assertFalse($closure->isAvailable($request->fresh()));
    }

    private function createSiteRequest(AdminApiTestContext $context, Project $project): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Foreign visible request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'medium',
            'material_name' => 'Foreign material',
            'material_quantity' => 1,
            'material_unit' => 'pcs',
        ]);
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

    private function createMaterial(int $organizationId, int $unitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $unitId,
            'category' => 'Site requests',
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'site-requests');
        });
    }

    private function allowAdminAccess(?callable $permissionCheck = null, array $modulePermissions = []): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($permissionCheck, $modulePermissions): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn(['modules' => ['site-requests' => $modulePermissions]]);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permissionCheck === null || $permissionCheck($permission)
            );
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
