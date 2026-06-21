<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimate;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateBudgetTransferOperation;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateLineItem;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateVersion;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PresaleEstimateBudgetTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_mapped_rows_for_presale_estimate(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/preview', $this->payload($source, $setup));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.ready_to_convert', true);
        $response->assertJsonPath('data.source.source_type', 'presale_estimate');
        $response->assertJsonPath('data.rows.0.mapping_status', 'mapped');
        $response->assertJsonPath('data.rows.0.budget_article_id', $setup['article']->uuid);
        $response->assertJsonPath('data.rows.0.responsibility_center_id', $setup['center']->uuid);
        $response->assertJsonPath('data.summary.included_rows_count', 1);
        $response->assertJsonPath('data.summary.plan_total', '250000.00');
    }

    public function test_preview_returns_blockers_for_unmapped_rows(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup, mapped: false);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/preview', $this->payload($source, $setup));

        $response->assertOk();
        $response->assertJsonPath('data.ready_to_convert', false);
        $response->assertJsonPath('data.rows.0.mapping_status', 'unmapped');

        $blockerKeys = collect($response->json('data.blockers'))->pluck('key')->all();

        $this->assertContains('row_budget_article_required', $blockerKeys);
        $this->assertContains('row_responsibility_center_required', $blockerKeys);
    }

    public function test_validate_blocks_transfer_when_amounts_are_hidden(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccessWithoutAmountPermissions();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/validate', $this->payload($source, $setup));

        $response->assertOk();
        $response->assertJsonPath('data.amount.amount_visible', false);
        $response->assertJsonPath('data.rows.0.amount', null);
        $response->assertJsonPath('data.ready_to_convert', false);

        $this->assertContains(
            'amount_permission_required',
            collect($response->json('data.blockers'))->pluck('key')->all()
        );
    }

    public function test_convert_creates_budget_lines_amounts_and_operation_without_touching_project_planned_cost(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);
        $projectBudgetAmount = $setup['project']->budget_amount;

        $payload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-key-1');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'converted');
        $response->assertJsonPath('data.budget_version.id', $setup['version']->uuid);
        $response->assertJsonPath('data.summary.lines_created', 1);

        $this->assertSame(1, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
        $this->assertSame(1, BudgetAmount::query()->whereHas('line', fn ($query) => $query->where('budget_version_id', $setup['version']->id))->count());
        $this->assertDatabaseHas('budget_amounts', [
            'plan_amount' => 250000,
            'forecast_amount' => 250000,
            'currency' => 'RUB',
        ]);
        $this->assertDatabaseHas('presale_estimate_budget_transfer_operations', [
            'organization_id' => $context->organization->id,
            'idempotency_key' => 'presale-transfer-key-1',
            'status' => 'completed',
            'project_id' => $setup['project']->id,
            'contract_id' => $setup['contract']->id,
            'budget_version_id' => $setup['version']->id,
        ]);
        $this->assertSame($projectBudgetAmount, $setup['project']->refresh()->budget_amount);
    }

    public function test_convert_replays_same_idempotency_key_without_duplicate_lines(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);
        $payload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-key-replay');

        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $payload);
        $first->assertCreated();

        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $payload);

        $second->assertOk();
        $second->assertJsonPath('data.status', 'already_converted');
        $second->assertJsonPath('data.idempotent_replay', true);
        $this->assertSame(1, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
    }

    public function test_convert_returns_existing_result_for_same_source_project_and_budget_version_with_new_key(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $firstPayload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-key-original');
        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $firstPayload);
        $first->assertCreated();

        $secondPayload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-key-new');
        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $secondPayload);

        $second->assertOk();
        $second->assertJsonPath('data.status', 'already_converted');
        $second->assertJsonPath('data.idempotent_replay', false);
        $this->assertSame(1, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
    }

    public function test_convert_returns_existing_result_when_new_budget_version_is_requested_after_completed_transfer(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $firstPayload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-existing-version');
        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $firstPayload);
        $first->assertCreated();

        $secondBasePayload = $this->createVersionPayload($source, $setup);
        $validate = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/validate', $secondBasePayload);
        $validate->assertOk();
        $validate->assertJsonPath('data.ready_to_convert', true);

        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', [
                ...$secondBasePayload,
                'preview_hash' => $validate->json('data.preview_hash'),
                'idempotency_key' => 'presale-transfer-create-version-again',
                'confirmed' => true,
            ]);

        $second->assertOk();
        $second->assertJsonPath('data.status', 'already_converted');
        $second->assertJsonPath('data.idempotent_replay', false);
        $second->assertJsonPath('data.budget_version.id', $setup['version']->uuid);
        $this->assertSame(1, BudgetVersion::query()->where('organization_id', $context->organization->id)->count());
        $this->assertSame(1, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
    }

    public function test_convert_is_forbidden_without_transfer_permission(): void
    {
        $context = AdminApiTestContext::create();
        $this->denyTransferConvertPermission();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', [
                ...$this->payload($source, $setup),
                'idempotency_key' => 'presale-transfer-forbidden',
                'confirmed' => true,
            ]);

        $response->assertForbidden();
    }

    public function test_convert_requires_explicit_confirmation_and_creates_no_rows(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', [
                ...$this->payload($source, $setup),
                'idempotency_key' => 'presale-transfer-without-confirm',
            ]);

        $response->assertUnprocessable();
        $this->assertSame(0, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
        $this->assertSame(0, PresaleEstimateBudgetTransferOperation::query()->count());
    }

    public function test_convert_rolls_back_budget_lines_when_write_fails(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createBudgetSetup($context);
        $source = $this->createPresaleSource($context, $setup);
        $payload = $this->validatedPayload($context, $source, $setup, 'presale-transfer-rollback');

        $this->mock(BudgetLineService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('writeNormalizedRows')
                ->once()
                ->withAnyArgs()
                ->andThrow(new RuntimeException('budget line write failed'));
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/convert', $payload);

        $response->assertServerError();
        $this->assertSame(0, BudgetLine::query()->where('budget_version_id', $setup['version']->id)->count());
        $this->assertSame(0, PresaleEstimateBudgetTransferOperation::query()->where('idempotency_key', 'presale-transfer-rollback')->count());
    }

    private function validatedPayload(AdminApiTestContext $context, array $source, array $setup, string $idempotencyKey): array
    {
        $validate = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/presale-estimates/budget-transfer/validate', $this->payload($source, $setup));

        $validate->assertOk();
        $validate->assertJsonPath('data.ready_to_convert', true);

        return [
            ...$this->payload($source, $setup),
            'preview_hash' => $validate->json('data.preview_hash'),
            'idempotency_key' => $idempotencyKey,
            'confirmed' => true,
        ];
    }

    private function payload(array $source, array $setup): array
    {
        return [
            'presale_estimate_id' => $source['estimate']->id,
            'target' => [
                'project_id' => $setup['project']->id,
                'contract_id' => $setup['contract']->id,
                'budget_version_id' => $setup['version']->uuid,
                'default_month' => '2026-02',
            ],
        ];
    }

    private function createVersionPayload(array $source, array $setup): array
    {
        return [
            'presale_estimate_id' => $source['estimate']->id,
            'target' => [
                'project_id' => $setup['project']->id,
                'contract_id' => $setup['contract']->id,
                'budget_version_id' => null,
                'default_month' => '2026-02',
                'create_budget_version' => [
                    'budget_period_id' => $setup['period']->uuid,
                    'scenario_id' => $setup['scenario']->uuid,
                    'budget_kind' => 'bdr',
                    'name' => 'Бюджет из presale-сметы',
                    'description' => 'Проверка защиты от повторного переноса',
                ],
            ],
        ];
    }

    private function createBudgetSetup(AdminApiTestContext $context): array
    {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Проект переноса сметы',
            'budget_amount' => 999999,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'number' => 'PHERP-111',
            'date' => '2026-01-10',
            'subject' => 'Работы по проекту',
            'status' => ContractStatusEnum::DRAFT,
            'contract_side_type' => ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR,
            'base_amount' => 250000,
            'total_amount' => 250000,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $period = BudgetPeriod::query()->create([
            'organization_id' => $context->organization->id,
            'code' => '2026',
            'name' => '2026',
            'period_type' => 'annual',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'status' => 'open',
        ]);

        $scenario = BudgetScenario::query()->create([
            'organization_id' => $context->organization->id,
            'code' => 'base',
            'name' => 'Базовый',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);

        $article = BudgetArticle::query()->create([
            'organization_id' => $context->organization->id,
            'code' => '20.10',
            'name' => 'Строительно-монтажные работы',
            'budget_kind' => 'bdr',
            'flow_direction' => 'expense',
            'is_leaf' => true,
            'is_active' => true,
        ]);

        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $context->organization->id,
            'center_type' => 'project',
            'code' => 'PRJ',
            'name' => 'Проекты',
            'is_active' => true,
        ]);

        $version = BudgetVersion::query()->create([
            'organization_id' => $context->organization->id,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => 'bdr',
            'version_number' => 1,
            'name' => 'Бюджет проекта',
            'status' => 'draft',
            'created_by' => $context->user->id,
            'workflow_history' => [],
        ])->load(['period', 'scenario']);

        return compact('project', 'contract', 'period', 'scenario', 'article', 'center', 'version');
    }

    private function createPresaleSource(AdminApiTestContext $context, array $setup, bool $mapped = true): array
    {
        $estimate = PresaleEstimate::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $setup['project']->id,
            'contract_id' => $setup['contract']->id,
            'number' => 'PS-111',
            'title' => 'Presale-смета PHERP-111',
            'status' => 'accepted',
            'subtotal_amount' => 250000,
            'total_amount' => 250000,
            'currency' => 'RUB',
            'created_by_user_id' => $context->user->id,
        ]);

        $version = PresaleEstimateVersion::query()->create([
            'organization_id' => $context->organization->id,
            'presale_estimate_id' => $estimate->id,
            'version_number' => 1,
            'status' => 'accepted',
            'title' => 'Версия 1',
            'totals_snapshot' => ['total_amount' => 250000],
            'created_by_user_id' => $context->user->id,
            'accepted_at' => now(),
        ]);

        $estimate->update([
            'current_version_id' => $version->id,
            'accepted_version_id' => $version->id,
        ]);

        $line = PresaleEstimateLineItem::query()->create([
            'organization_id' => $context->organization->id,
            'presale_estimate_id' => $estimate->id,
            'presale_estimate_version_id' => $version->id,
            'budget_article_id' => $mapped ? $setup['article']->id : null,
            'responsibility_center_id' => $mapped ? $setup['center']->id : null,
            'planned_month' => '2026-02-01',
            'line_type' => 'work',
            'title' => 'Монтажные работы',
            'unit' => 'компл',
            'quantity' => 1,
            'unit_cost' => 250000,
            'subtotal_amount' => 250000,
            'total_amount' => 250000,
            'sort_order' => 1,
        ]);

        return compact('estimate', 'version', 'line');
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

    private function allowAdminAccessWithoutAmountPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, array $context = []): bool => ! in_array($permission, [
                    'presale_estimates.amounts.view',
                    'commercial_proposals.amounts.view',
                    'tenders.amounts.view',
                    'crm.amounts.view',
                ], true)
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

    private function denyTransferConvertPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, array $context = []): bool => $permission !== 'presale_estimates.transfer.convert'
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
