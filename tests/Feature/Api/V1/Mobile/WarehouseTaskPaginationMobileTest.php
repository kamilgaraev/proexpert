<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseTaskPaginationMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_list_keeps_legacy_array_and_supports_scoped_pages(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');
        $foreignWarehouse = $this->createWarehouse((int) $foreignContext->organization->id, 'FOREIGN');

        foreach ([1, 2, 3] as $number) {
            $this->createTask((int) $context->organization->id, $warehouse, $number);
        }
        $this->createTask((int) $foreignContext->organization->id, $foreignWarehouse, 1);
        $this->allowAccess();

        $headers = $context->mobileAuthHeaders();
        $path = "/api/v1/mobile/warehouse/warehouses/{$warehouse->id}/tasks";

        $this->withHeaders($headers)
            ->getJson($path.'?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($headers)
            ->getJson($path.'?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', true);

        $this->withHeaders($headers)
            ->getJson($path.'?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more', false);

        $this->withHeaders($headers)
            ->getJson($path.'?page=0&per_page=2')
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->getJson("/api/v1/mobile/warehouse/warehouses/{$foreignWarehouse->id}/tasks?page=1&per_page=2")
            ->assertNotFound();
    }

    private function createWarehouse(int $organizationId, string $code): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $code,
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    private function createTask(int $organizationId, OrganizationWarehouse $warehouse, int $number): WarehouseTask
    {
        return WarehouseTask::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouse->id,
            'task_number' => "WH-{$organizationId}-{$number}",
            'title' => "Warehouse task {$number}",
            'task_type' => WarehouseTask::TYPE_TRANSFER,
            'status' => WarehouseTask::STATUS_QUEUED,
            'priority' => WarehouseTask::PRIORITY_NORMAL,
        ]);
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
        });
    }
}
