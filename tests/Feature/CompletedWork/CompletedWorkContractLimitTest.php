<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkContractLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_checks_contract_limit_against_price_times_quantity(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization);
        $contract = $this->createContract($context->organization, $project, $contractor, [
            'total_amount' => 1000,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            [
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'contractor_id' => $contractor->id,
                'quantity' => 2,
                'price' => 600,
                'completion_date' => '2026-09-15',
                'status' => CompletedWork::STATUS_PENDING,
            ],
        );

        $response->assertUnprocessable();
        self::assertStringContainsString('Превышен лимит контракта', (string) $response->json('message'));
        $this->assertDatabaseMissing('completed_works', [
            'contract_id' => $contract->id,
            'quantity' => 2,
        ]);
    }

    public function test_coefficient_is_applied_before_contract_limit_check(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization);
        $contract = $this->createContract($context->organization, $project, $contractor, [
            'total_amount' => 1100,
        ]);
        $this->mock(RateCoefficientService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('calculateAdjustedValueDetailed')
                ->once()
                ->andReturnUsing(function (...$arguments): array {
                    self::assertSame(1000.0, (float) $arguments[1]);
                    self::assertSame(RateCoefficientAppliesToEnum::WORK_COSTS->value, $arguments[2]);

                    return [
                        'original' => 1000.0,
                        'final' => 1200.0,
                        'applications' => [],
                    ];
                });
        });
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            [
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'contractor_id' => $contractor->id,
                'quantity' => 1,
                'price' => 1000,
                'completion_date' => '2026-09-15',
                'status' => CompletedWork::STATUS_PENDING,
            ],
        );

        $response->assertUnprocessable();
        self::assertStringContainsString('Превышен лимит контракта', (string) $response->json('message'));
    }

    public function test_updating_without_financial_changes_does_not_apply_coefficient_twice(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization);
        $contract = $this->createContract($context->organization, $project, $contractor, [
            'total_amount' => 10000,
        ]);
        $this->mock(RateCoefficientService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('calculateAdjustedValueDetailed')
                ->once()
                ->andReturn([
                    'original' => 1000.0,
                    'final' => 1200.0,
                    'applications' => [],
                ]);
        });
        $this->allowAdminAccess();

        $created = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            [
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'contractor_id' => $contractor->id,
                'quantity' => 1,
                'price' => 1000,
                'completion_date' => '2026-09-15',
                'status' => CompletedWork::STATUS_PENDING,
            ],
        )->assertCreated();
        $workId = $created->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$workId}", [
                'notes' => 'Без изменения финансовых полей',
            ])
            ->assertOk();

        self::assertSame(1200.0, (float) CompletedWork::query()->findOrFail($workId)->total_amount);
    }

    public function test_reassigning_contract_checks_new_contract_even_when_amount_and_status_are_unchanged(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization);
        $sourceContract = $this->createContract($context->organization, $project, $contractor, [
            'number' => 'SOURCE-CONTRACT',
            'total_amount' => 10000,
        ]);
        $targetContract = $this->createContract($context->organization, $project, $contractor, [
            'number' => 'TARGET-CONTRACT',
            'total_amount' => 50,
        ]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contract_id' => $sourceContract->id,
            'contractor_id' => $contractor->id,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'completion_date' => '2026-09-15',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())->putJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}",
            ['contract_id' => $targetContract->id],
        );

        $response->assertUnprocessable();
        self::assertSame($sourceContract->id, $work->fresh()->contract_id);
    }

    private function createContractor(Organization $organization): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Тестовый подрядчик',
        ]);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        array $overrides = [],
    ): Contract {
        return Contract::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'CONTRACT-'.random_int(1000, 9999),
            'date' => '2026-09-01',
            'subject' => 'Работы',
            'base_amount' => 10000,
            'total_amount' => 10000,
            'currency' => 'RUB',
            'status' => 'active',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
        ], $overrides));
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
                },
            );
        });
    }
}
