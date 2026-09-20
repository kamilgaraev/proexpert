<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkCanonicalQuantityTest extends TestCase
{
    public function test_new_fact_has_one_quantity_for_money_and_acting(): void
    {
        [$context, $project] = $this->context();

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            ['project_id' => $project->id, 'quantity' => 10, 'price' => 50,
                'completion_date' => '2026-09-20', 'status' => 'pending'],
        );

        $response->assertCreated();
        $work = CompletedWork::query()->findOrFail($response->json('data.id'));
        self::assertSame('10.0000', $work->completed_quantity);
        self::assertSame('500.00', $work->total_amount);
        self::assertSame(10.0, $work->effectiveCompletedQuantity());
    }

    public function test_conflicting_quantities_cannot_create_a_new_fact(): void
    {
        [$context, $project] = $this->context();

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            ['project_id' => $project->id, 'quantity' => 100, 'completed_quantity' => 10,
                'price' => 50, 'completion_date' => '2026-09-20', 'status' => 'pending'],
        )->assertUnprocessable();

        self::assertSame(0, CompletedWork::query()->where('project_id', $project->id)->count());
    }

    public function test_fractional_fact_preserves_the_same_four_decimal_quantity_in_both_fields(): void
    {
        [$context, $project] = $this->context();
        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            ['project_id' => $project->id, 'quantity' => '10.0001', 'price' => 50,
                'completion_date' => '2026-09-20', 'status' => 'pending'],
        )->assertCreated();

        $work = CompletedWork::query()->findOrFail($response->json('data.id'));
        self::assertSame('10.0001', $work->quantity);
        self::assertSame($work->quantity, $work->completed_quantity);
    }

    public function test_confirmed_fact_cannot_be_reduced_by_ordinary_update(): void
    {
        [$context, $project] = $this->context();
        $work = $this->fact($context, $project);

        $this->withHeaders($context->authHeaders())->putJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}",
            ['project_id' => $project->id, 'quantity' => 3, 'completed_quantity' => 3],
        )->assertUnprocessable();

        self::assertSame('10.0000', $work->fresh()->completed_quantity);
        self::assertSame(CompletedWork::STATUS_CONFIRMED, $work->fresh()->status);
    }

    public function test_confirmed_fact_cannot_be_deleted_by_ordinary_delete(): void
    {
        [$context, $project] = $this->context();
        $work = $this->fact($context, $project);

        $this->withHeaders($context->authHeaders())->deleteJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}",
        )->assertUnprocessable();

        self::assertNotNull($work->fresh());
        self::assertNull($work->fresh()->deleted_at);
    }

    public function test_legacy_fact_without_completed_quantity_remains_in_estimate_progress(): void
    {
        [$context, $project] = $this->context();
        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'number' => 'CANONICAL-1', 'name' => 'Проверка физического объёма',
            'status' => 'draft', 'estimate_date' => '2026-09-20',
        ]);
        $unit = MeasurementUnit::query()->where('organization_id', $context->organization->id)->firstOrFail();
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'measurement_unit_id' => $unit->id,
            'position_number' => '1', 'item_type' => 'work', 'name' => 'Работа',
            'quantity' => 100, 'quantity_total' => 100, 'unit_price' => 50,
            'current_unit_price' => 50, 'total_amount' => 5000, 'current_total_amount' => 5000,
        ]);
        $work = $this->fact($context, $project, ['estimate_item_id' => $item->id, 'completed_quantity' => null]);

        self::assertSame(10.0, $work->effectiveCompletedQuantity());
        self::assertSame(10.0, $item->getActualVolume());
    }

    private function fact(AdminApiTestContext $context, Project $project, array $overrides = []): CompletedWork
    {
        return CompletedWork::query()->create(array_replace([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'quantity' => 10, 'completed_quantity' => 10, 'price' => 50, 'total_amount' => 500,
            'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ], $overrides));
    }

    private function context(): array
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface', 'can', 'hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()
                    ->where('is_active', true)
                    ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                    ->get(),
            );
        });

        return [$context, $project];
    }
}
