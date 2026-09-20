<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeAcceptedAllocationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\PerformanceActLine;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementLegacySourceTest extends TestCase
{
    public static function sourceKinds(): array
    {
        return [
            'legacy' => ['legacy', false], 'manual' => ['manual', false], 'unlinked' => ['unlinked', false],
            'execution' => ['execution', false], 'unknown_execution' => ['unknown_execution', false], 'zero_execution' => ['zero_execution', false],
            'legacy_known_basis' => ['legacy', true], 'manual_known_basis' => ['manual', true],
            'unlinked_known_basis' => ['unlinked', true], 'native_known_basis' => ['native', true],
        ];
    }

    #[DataProvider('sourceKinds')]
    public function test_legacy_accepted_volume_requires_reconciliation_instead_of_becoming_zero(string $kind, bool $specifiedBasis): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'WVS-LEGACY', 'date' => '2026-09-01', 'total_amount' => 10000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        $type = WorkType::query()->create(['organization_id' => $context->organization->id, 'name' => 'Стена']);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'work_type_id' => $type->id, 'user_id' => $context->user->id, 'quantity' => 100,
            'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => 'OLD-80',
            'act_date' => '2026-09-20', 'amount' => 800, 'status' => 'draft', 'is_approved' => false,
        ]);
        if ($kind === 'legacy') {
            DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $act->id, 'completed_work_id' => $work->id,
            'included_quantity' => '80', 'included_amount' => '800',
            ]);
        } elseif (str_contains($kind, 'execution')) {
            $estimate = Estimate::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'number' => 'LEGACY', 'name' => 'Основание', 'status' => 'draft', 'estimate_date' => '2026-09-01',
            ]);
            $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Стена', 'quantity' => 100]);
            $allocation = DB::table('estimate_finance_allocations')->insertGetId([
                'key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'organization_id' => $context->organization->id,
                'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'contract_id' => $contract->id,
                'side' => 'expense', 'source' => 'manual', 'currency' => 'RUB', 'quantity' => 100,
                'price_basis' => 'manual', 'method' => 'manual', 'estimate_snapshot' => '{}', 'updated_by' => $context->user->id,
            ]);
            DB::table('estimate_finance_execution_allocations')->insert([
                'key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'organization_id' => $context->organization->id,
                'project_id' => $project->id, 'estimate_id' => $estimate->id, 'allocation_id' => $allocation, 'performance_act_id' => $act->id,
                'currency' => 'RUB', 'quantity' => $kind === 'unknown_execution' ? null : ($kind === 'zero_execution' ? '0' : '80'),
                'amount_with_vat' => $kind === 'zero_execution' ? '0' : '800', 'condition_version' => 1, 'source_hash' => str_repeat('a', 64),
                'source_snapshot' => '{}', 'condition_snapshot' => '{}', 'updated_by' => $context->user->id,
            ]);
        } else {
            DB::table('performance_act_lines')->insert([
                'performance_act_id' => $act->id, 'line_type' => $kind === 'manual' ? PerformanceActLine::TYPE_MANUAL : PerformanceActLine::TYPE_COMPLETED_WORK,
                'completed_work_id' => $kind === 'native' ? $work->id : null,
                'title' => 'Историческая строка', 'unit' => 'м²', 'quantity' => '80', 'unit_price' => '10', 'amount' => '800',
            ]);
        }
        $statement = WorkVolumeStatement::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'statement_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'version' => 1, 'name' => 'ВОР', 'status' => 'draft',
        ]);
        $basisItemId = null;
        if ($specifiedBasis) {
            $basis = Estimate::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'number' => 'WVS-BASIS', 'name' => 'Проектное основание', 'status' => 'draft', 'estimate_date' => '2026-09-01',
            ]);
            $basisItemId = EstimateItem::query()->create(['estimate_id' => $basis->id, 'position_number' => '1', 'name' => 'Стена', 'quantity' => 100])->id;
        }
        $statement->lines()->create([
            'line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Стена', 'unit_code' => 'м²',
            'quantity' => '70', 'place' => ['axis' => 'А-1'], 'estimate_item_id' => $basisItemId,
        ]);
        $guard = app(WorkVolumeAcceptedAllocationService::class);
        $guard->assertSourcesMapped($statement);
        $act->forceFill(['status' => 'approved', 'is_approved' => true])->save();
        if ($kind === 'zero_execution') {
            $guard->assertSourcesMapped($statement);
            self::assertSame('70.000000', $statement->lines()->first()->quantity);
            return;
        }
        try {
            $guard->assertSourcesMapped($statement);
            self::fail('Старый принятый объём нельзя считать нулевым');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame(trans_message('budget_estimates.work_volume_statements.accepted_source_unmapped'), $exception->getMessage());
        }
    }
}
