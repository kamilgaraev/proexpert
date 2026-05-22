<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SiteRequestsMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_create_update_and_admin_readback_stay_in_sync(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/site-requests', [
                'project_id' => $project->id,
                'title' => 'Mobile concrete request',
                'description' => 'Concrete for slab section A',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'priority' => SiteRequestPriorityEnum::HIGH->value,
                'required_date' => now()->addDays(2)->toDateString(),
                'material_name' => 'Concrete M350',
                'material_quantity' => 6,
                'material_unit' => 'm3',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.title', 'Mobile concrete request')
            ->assertJsonPath('data.status', SiteRequestStatusEnum::DRAFT->value);

        $requestId = (int) $createResponse->json('data.id');

        $this->assertDatabaseHas('site_requests', [
            'id' => $requestId,
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Mobile concrete request',
            'material_quantity' => 6,
            'material_unit' => 'm3',
        ]);

        $adminShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests/{$requestId}");

        $adminShowResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Mobile concrete request')
            ->assertJsonPath('data.material_quantity', static fn ($value): bool => (float) $value === 6.0);

        $adminUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/{$requestId}", [
                'title' => 'Admin clarified concrete request',
                'description' => 'Admin updated delivery context',
                'priority' => SiteRequestPriorityEnum::URGENT->value,
                'material_quantity' => 8,
                'material_unit' => 'm3',
            ]);

        $adminUpdateResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Admin clarified concrete request')
            ->assertJsonPath('data.priority', SiteRequestPriorityEnum::URGENT->value);

        $mobileRefreshResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$requestId}");

        $mobileRefreshResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Admin clarified concrete request')
            ->assertJsonPath('data.priority', SiteRequestPriorityEnum::URGENT->value)
            ->assertJsonPath('data.material_quantity', static fn ($value): bool => (float) $value === 8.0);

        $adminListResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests?project_id={$project->id}&search=clarified");

        $adminListResponse->assertOk();
        $this->assertContains($requestId, collect($adminListResponse->json('data'))->pluck('id')->all());
    }

    public function test_mobile_detail_uses_snake_case_procurement_contract_only(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Mobile concrete request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete M300',
            'material_quantity' => 12,
            'material_unit' => 'm3',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$siteRequest->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $siteRequest->id)
            ->assertJsonPath('data.purchase_requests', [])
            ->assertJsonPath('data.purchase_orders', []);

        $payload = $response->json('data');

        $this->assertArrayNotHasKey('purchaseRequests', $payload);
        $this->assertArrayNotHasKey('purchaseOrders', $payload);
        $this->assertArrayHasKey('available_transitions', $payload);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'modules' => [
                    'site-requests' => [
                        'site_requests.view',
                        'site_requests.create',
                        'site_requests.change_status',
                    ],
                ],
            ]);
        });
    }
}
