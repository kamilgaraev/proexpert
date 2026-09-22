<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeUnmappedFactsQuery;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use App\Models\User;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeUnmappedFactsTest extends TestCase
{
    public function test_http_scope_allows_shared_project_but_does_not_leak_owner_facts(): void
    {
        $owner = AdminApiTestContext::create();
        $owner->user->organizations()->updateExistingPivot($owner->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $owner->organization->id]);
        $shared = AdminApiTestContext::create();
        $shared->user->organizations()->updateExistingPivot($shared->organization->id, ['project_access_mode' => 'all_projects']);
        $project->organizations()->attach($shared->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();

        $contractor = Contractor::query()->create(['organization_id' => $owner->organization->id, 'name' => 'Владелец']);
        $contract = Contract::query()->create([
            'organization_id' => $owner->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'SHARED-OWNER', 'date' => '2026-09-20', 'total_amount' => 1000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        $ownerAct = $this->act($contract, 'SHARED-OWNER-ACT');
        DB::table('performance_act_lines')->insert([
            'performance_act_id' => $ownerAct->id, 'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => 'Факт владельца', 'unit' => 'м²', 'quantity' => '1.0000', 'unit_price' => '10', 'amount' => '10',
        ]);
        self::assertSame(1, app(WorkVolumeUnmappedFactsQuery::class)->paginate($owner->user, $project->id)->total());

        $this->withHeaders([...$shared->authHeaders(), 'Accept' => 'application/json'])
            ->getJson("/api/v1/admin/projects/{$project->id}/work-volume-statements/unmapped-facts")
            ->assertOk()->assertJsonPath('meta.total', 0);

        $foreign = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $this->withHeaders([...$shared->authHeaders(), 'Accept' => 'application/json'])
            ->getJson("/api/v1/admin/projects/{$foreignProject->id}/work-volume-statements/unmapped-facts")
            ->assertForbidden();

        $shared->user->organizations()->updateExistingPivot($shared->organization->id, ['project_access_mode' => 'assigned_projects']);
        $unassigned = Project::factory()->create(['organization_id' => $shared->organization->id]);
        $this->withHeaders([...$shared->authHeaders(), 'Accept' => 'application/json'])
            ->getJson("/api/v1/admin/projects/{$unassigned->id}/work-volume-statements/unmapped-facts")
            ->assertForbidden();
    }

    public function test_it_lists_unmapped_manual_legacy_and_execution_sources_with_exact_quantities(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();

        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'UNMAPPED-1', 'date' => '2026-09-20', 'total_amount' => 10000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        $type = WorkType::query()->create(['organization_id' => $context->organization->id, 'name' => 'Стена']);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'work_type_id' => $type->id, 'user_id' => $context->user->id, 'quantity' => '100.0000',
            'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
        ]);

        $manualAct = $this->act($contract, 'MANUAL-1');
        DB::table('performance_act_lines')->insert([
            'performance_act_id' => $manualAct->id, 'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => 'Ручная строка', 'unit' => 'м²', 'quantity' => '3.1250', 'unit_price' => '10', 'amount' => '31.25',
        ]);

        $legacyAct = $this->act($contract, 'LEGACY-1');
        DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $legacyAct->id, 'completed_work_id' => $work->id,
            'included_quantity' => '4.250', 'included_amount' => '42.50',
        ]);

        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'number' => 'UNMAPPED-EST', 'name' => 'Основание', 'status' => 'draft', 'estimate_date' => '2026-09-01',
        ]);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Стена', 'quantity' => 100]);
        $allocation = DB::table('estimate_finance_allocations')->insertGetId([
            'key' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'organization_id' => $context->organization->id,
            'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'contract_id' => $contract->id,
            'side' => 'expense', 'source' => 'manual', 'currency' => 'RUB', 'quantity' => 10,
            'price_basis' => 'manual', 'method' => 'manual', 'estimate_snapshot' => '{}', 'updated_by' => $context->user->id,
        ]);
        $executionAct = $this->act($contract, 'EXEC-1');
        DB::table('estimate_finance_execution_allocations')->insert([
            'key' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'organization_id' => $context->organization->id,
            'project_id' => $project->id, 'estimate_id' => $estimate->id, 'allocation_id' => $allocation,
            'performance_act_id' => $executionAct->id, 'currency' => 'RUB', 'quantity' => null,
            'amount_with_vat' => '25', 'condition_version' => 1, 'source_hash' => str_repeat('a', 64),
            'source_snapshot' => '{}', 'condition_snapshot' => '{}', 'updated_by' => $context->user->id,
        ]);

        $nativeAct = $this->act($contract, 'NATIVE-1');
        $nativeLine = DB::table('performance_act_lines')->insertGetId([
            'performance_act_id' => $nativeAct->id, 'completed_work_id' => $work->id, 'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Каноническая строка', 'unit' => 'м³', 'quantity' => '16', 'unit_price' => '10', 'amount' => '160',
        ]);
        $statement = WorkVolumeStatement::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'statement_key' => '11111111-1111-4111-8111-111111111111', 'version' => 1, 'name' => 'ВОР', 'status' => WorkVolumeStatement::STATUS_DRAFT,
        ]);
        $statementLine = $statement->lines()->create([
            'line_key' => '22222222-2222-4222-8222-222222222222', 'name' => 'Каноническая строка', 'unit_code' => 'м²', 'quantity' => '80', 'place' => ['axis' => 'А-1'],
        ]);
        $mapping = DB::table('work_volume_acceptance_mappings')->insertGetId([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'performance_act_line_id' => $nativeLine,
            'revision' => 1, 'operation_key' => 'unmapped-native-1', 'operation_hash' => str_repeat('b', 64),
            'source_quantity' => '16', 'source_unit' => 'м³', 'reason' => 'Проверка', 'created_by' => $context->user->id, 'sealed' => false, 'created_at' => now(),
        ]);
        DB::table('work_volume_accepted_allocations')->insert([
            'mapping_id' => $mapping, 'statement_line_id' => $statementLine->id, 'quantity' => '40',
            'line_snapshot' => json_encode(['line_key' => $statementLine->line_key, 'statement_key' => $statement->statement_key, 'conversion_basis' => ['coefficient' => '0.2']], JSON_THROW_ON_ERROR),
        ]);
        DB::table('work_volume_acceptance_mappings')->where('id', $mapping)->update(['sealed' => true]);

        $result = app(WorkVolumeUnmappedFactsQuery::class)->paginate($context->user, $project->id, 100);
        self::assertSame(4, $result->total());
        $rows = $result->getCollection()->keyBy('source_kind');
        self::assertSame(['finance_execution', 'legacy', 'manual_or_unlinked_native', 'native'], $rows->keys()->sort()->values()->all());
        self::assertNull($rows['finance_execution']->source_quantity);
        self::assertNull($rows['finance_execution']->unmapped_quantity);
        self::assertSame('4.250000', (string) $rows['legacy']->unmapped_quantity);
        self::assertSame('3.125000', (string) $rows['manual_or_unlinked_native']->unmapped_quantity);
        self::assertSame('16.000000', (string) $rows['native']->source_quantity);
        self::assertSame('8.000000', (string) $rows['native']->mapped_quantity);
        self::assertSame('8.000000', (string) $rows['native']->unmapped_quantity);

        $executionAct->forceFill(['annulled_at' => now()])->save();
        $afterAnnul = app(WorkVolumeUnmappedFactsQuery::class)->paginate($context->user, $project->id, 100);
        self::assertSame(3, $afterAnnul->total());
        self::assertFalse($afterAnnul->getCollection()->pluck('source_kind')->contains('finance_execution'));

        $latest = DB::table('work_volume_acceptance_mappings')->insertGetId([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'performance_act_line_id' => $nativeLine,
            'revision' => 2, 'operation_key' => 'unmapped-native-2', 'operation_hash' => str_repeat('c', 64),
            'source_quantity' => '16', 'source_unit' => 'м³', 'reason' => 'Проверка 2', 'created_by' => $context->user->id, 'sealed' => false, 'created_at' => now(),
        ]);
        DB::table('work_volume_accepted_allocations')->insert([
            'mapping_id' => $latest, 'statement_line_id' => $statementLine->id, 'quantity' => '80',
            'line_snapshot' => json_encode(['line_key' => $statementLine->line_key, 'statement_key' => $statement->statement_key, 'conversion_basis' => ['coefficient' => '0.2']], JSON_THROW_ON_ERROR),
        ]);
        DB::table('work_volume_acceptance_mappings')->where('id', $latest)->update(['sealed' => true]);
        $afterLatest = app(WorkVolumeUnmappedFactsQuery::class)->paginate($context->user, $project->id, 100);
        self::assertSame(2, $afterLatest->total());
        self::assertFalse($afterLatest->getCollection()->pluck('source_kind')->contains('native'));
    }

    private function act(Contract $contract, string $number): ContractPerformanceAct
    {
        return ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'act_document_number' => $number,
            'act_date' => '2026-09-20', 'amount' => 1000, 'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]);
    }
}
