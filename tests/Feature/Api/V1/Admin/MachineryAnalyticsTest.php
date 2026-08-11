<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(WebInterfaceSecurityMiddleware::class);
    }

    public function test_cost_report_requires_period_and_excludes_unapproved_and_future_shifts(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'API-COST-1',
            'name' => 'Кран',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 2000,
        ]);
        foreach ([['approved', now()->toDateString()], ['draft', now()->toDateString()], ['approved', now()->addMonth()->toDateString()]] as [$status, $date]) {
            MachineryShiftReport::query()->create([
                'organization_id' => $context->organization->id,
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => $date,
                'status' => $status,
                'actual_hours' => 2,
                'planned_hours' => 2,
                'fuel_consumed' => 0,
                'hourly_rate_snapshot' => 2000,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
        }
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/reports/costs')
            ->assertStatus(422);
        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/reports/costs?'.http_build_query([
                'date_from' => now()->startOfMonth()->toDateString(),
                'date_to' => now()->endOfMonth()->toDateString(),
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.source_status', 'approved_only')
            ->assertJsonPath('data.totals.operation_cost', 4000)
            ->assertJsonCount(1, 'data.evidence.shifts');
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
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()
                    ->where('is_active', true)
                    ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                    ->get(),
            );
        });
    }
}
