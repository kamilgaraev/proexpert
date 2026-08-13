<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class AssetDispatchWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(WebInterfaceSecurityMiddleware::class);
    }

    public function test_request_candidates_and_dispatch_create_canonical_links_and_immutable_audit(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'latitude' => 55.75,
            'longitude' => 37.61,
        ]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();
        $asset = MachineryOperationsAssetFactory::create(
            (int) $context->organization->id,
            ['asset_code' => 'DSP-1', 'name' => 'Dispatcher excavator', 'operating_cost_per_hour' => 1000],
        );
        $misleadingLegacyAsset = MachineryOperationsAssetFactory::create(
            (int) $context->organization->id,
            ['asset_code' => 'DSP-LEGACY-TRAP', 'name' => 'Higher canonical cost', 'operating_cost_per_hour' => 2000],
        );
        $misleadingLegacyAsset->update(['operating_cost_per_hour' => 1]);

        $request = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests', [
            'project_id' => $project->id,
            'planned_start_at' => now()->addDay()->toIso8601String(),
            'planned_end_at' => now()->addDays(2)->toIso8601String(),
            'purpose' => 'Разработка котлована',
            'required_profile' => ['tracks_meter' => true],
        ]);
        $request->assertCreated()->assertJsonPath('data.status', 'pending');
        $requestId = (int) $request->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/asset-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId);
        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/overview')
            ->assertOk()
            ->assertJsonPath('data.pending_requests', 1);
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/machinery-operations/assets/{$asset->id}/workspace")
            ->assertOk()
            ->assertJsonPath('data.asset.id', $asset->id)
            ->assertJsonPath('data.costs.fuel', 0);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/machinery-operations/asset-requests/{$requestId}/candidates")
            ->assertOk()
            ->assertJsonPath('data.0.asset.id', $asset->id)
            ->assertJsonPath('data.0.asset.organization_asset_id', $asset->organization_asset_id)
            ->assertJsonPath('data.0.eligible', true);

        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests/assign', [
            'asset_request_id' => $requestId,
            'organization_asset_id' => $asset->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => now()->addDay()->toIso8601String(),
            'planned_end_at' => now()->addDays(2)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.organization_asset_id', $asset->organization_asset_id);

        $this->assertDatabaseHas('asset_requests', ['id' => $requestId, 'status' => 'assigned']);
        $this->assertDatabaseCount('asset_request_events', 2);

        DB::statement('SAVEPOINT immutable_audit_check');
        $blocked = false;
        try {
            DB::table('asset_request_events')->where('asset_request_id', $requestId)->update(['event_type' => 'tampered']);
        } catch (QueryException) {
            $blocked = true;
            DB::statement('ROLLBACK TO SAVEPOINT immutable_audit_check');
        }
        self::assertTrue($blocked);
    }

    public function test_direct_assignment_requires_permission_and_reason_and_records_audit(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess(directAssign: true);
        $asset = MachineryOperationsAssetFactory::create(
            (int) $context->organization->id,
            ['asset_code' => 'DSP-2', 'name' => 'Direct crane'],
        );
        $payload = [
            'organization_asset_id' => $asset->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => now()->addDay()->toIso8601String(),
            'reason' => 'Аварийная замена',
        ];

        $assigned = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/asset-requests/direct-assign', $payload);
        self::assertSame(200, $assigned->status(), $assigned->getContent());

        $direct = AssetRequest::query()->where('purpose', 'Аварийная замена')->firstOrFail();
        self::assertSame('assigned', $direct->status);
        self::assertSame(['direct_requested', 'direct_assigned'], $direct->events()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_direct_assignment_is_denied_without_permission(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess(directAssign: false);
        $asset = MachineryOperationsAssetFactory::create(
            (int) $context->organization->id,
            ['asset_code' => 'DSP-DENIED', 'name' => 'Denied direct crane'],
        );

        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests/direct-assign', [
            'organization_asset_id' => $asset->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => now()->addDay()->toIso8601String(),
            'reason' => 'Нет полномочий',
        ])->assertStatus(403);

        $this->assertDatabaseCount('asset_requests', 0);
    }

    public function test_overlap_is_rejected_and_faulty_asset_can_be_replaced_with_audited_direct_assignment(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();
        $operations = $this->app->make(MachineryOperationsService::class);
        $faulty = MachineryOperationsAssetFactory::create((int) $context->organization->id, ['asset_code' => 'DSP-OLD', 'name' => 'Faulty asset']);
        $replacement = MachineryOperationsAssetFactory::create((int) $context->organization->id, ['asset_code' => 'DSP-NEW', 'name' => 'Replacement asset']);
        $start = now()->addDay();
        $end = now()->addDays(2);

        $requestId = (int) $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests', [
            'project_id' => $project->id,
            'planned_start_at' => $start->toIso8601String(),
            'planned_end_at' => $end->toIso8601String(),
            'purpose' => 'Основное назначение',
        ])->json('data.id');
        $first = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests/assign', [
            'asset_request_id' => $requestId,
            'organization_asset_id' => $faulty->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => $start->toIso8601String(),
            'planned_end_at' => $end->toIso8601String(),
        ])->assertOk();
        $firstAssignmentId = (int) $first->json('data.id');

        DB::statement('SAVEPOINT overlap_constraint_check');
        $databaseBlockedOverlap = false;
        try {
            MachineryAssignment::query()->create([
                'organization_id' => $context->organization->id,
                'organization_asset_id' => $faulty->organization_asset_id,
                'asset_id' => $faulty->id,
                'project_id' => $project->id,
                'status' => 'active',
                'planned_start_at' => $start->copy()->addHour(),
                'planned_end_at' => $end->copy()->addHour(),
            ]);
        } catch (QueryException) {
            $databaseBlockedOverlap = true;
            DB::statement('ROLLBACK TO SAVEPOINT overlap_constraint_check');
        }
        self::assertTrue($databaseBlockedOverlap);

        $overlapRequestId = (int) $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests', [
            'project_id' => $project->id,
            'planned_start_at' => $start->addHour()->toIso8601String(),
            'planned_end_at' => $end->addHour()->toIso8601String(),
            'purpose' => 'Пересекающаяся заявка',
        ])->json('data.id');
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests/assign', [
            'asset_request_id' => $overlapRequestId,
            'organization_asset_id' => $faulty->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => $start->toIso8601String(),
            'planned_end_at' => $end->toIso8601String(),
        ])->assertStatus(422);

        $operations->setUnavailable($faulty->refresh());
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/asset-requests/direct-assign', [
            'organization_asset_id' => $replacement->organization_asset_id,
            'project_id' => $project->id,
            'planned_start_at' => $start->toIso8601String(),
            'planned_end_at' => $end->toIso8601String(),
            'replaces_assignment_id' => $firstAssignmentId,
            'reason' => 'Замена неисправной единицы',
        ])->assertOk();

        self::assertSame('replaced', MachineryAssignment::query()->findOrFail($firstAssignmentId)->status);
        $this->assertDatabaseHas('machinery_assignments', [
            'organization_asset_id' => $replacement->organization_asset_id,
            'status' => 'active',
        ]);
        self::assertSame(2, AssetRequest::query()->where('status', 'assigned')->count());
    }

    private function allowAccess(bool $directAssign = true): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($directAssign): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission !== 'machinery-operations.direct_assign' || $directAssign,
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $authorizationContext = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}
