<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Project;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeCoverageConversionTest extends TestCase
{
    public function test_conversion_requires_approval_and_preserves_exact_target_quantity_and_confirmation(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $canApprove = true;
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(static function ($actor, string $permission) use (&$canApprove): bool {
            return $permission !== 'budget-estimates.approve' || $canApprove;
        });
        $from = MeasurementUnit::query()->create(['organization_id' => $context->organization->id, 'name' => 'Площадь', 'short_name' => 'м2', 'type' => 'work']);
        $to = MeasurementUnit::query()->create(['organization_id' => $context->organization->id, 'name' => 'Объём', 'short_name' => 'м3', 'type' => 'work']);
        $estimate = Estimate::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'number' => 'CONVERT', 'name' => 'Основание', 'status' => 'draft', 'estimate_date' => '2026-09-20']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Стена', 'quantity' => 20, 'measurement_unit_id' => $to->id]);
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'CONVERT', 'date' => '2026-09-20', 'total_amount' => 10000]);
        ContractEstimateItem::query()->create(['contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => 20]);
        $statements = app(WorkVolumeStatementService::class);
        $statement = $statements->createDraft($context->user, $project->id, ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => $from->short_name,
            'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]]);
        $statement = $statements->approve($context->user, $statements->submitForReview($context->user, $statement, 0), 1);
        $row = [
            'statement_line_id' => $statement->lines->first()->id, 'contract_id' => $contract->id, 'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id, 'quantity' => '100', 'unit_code' => $from->short_name,
            'conversion_basis' => ['coefficient' => '0.2', 'reason' => 'Толщина стены 0,2 м по обмеру'],
        ];
        $service = app(WorkVolumeCoverageService::class);
        $canApprove = false;
        try {
            $service->replaceAllocations($context->user, $statement, [$row], 'unauthorized-conversion', 0);
            self::fail('Пересчёт единиц должен подтверждать согласующий');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        $canApprove = true;
        $result = $service->replaceAllocations($context->user, $statement, [$row], 'confirmed-conversion', 0);
        self::assertSame('100.000000', $result['allocations'][0]['quantity']);
        self::assertSame('20.000000', $result['allocations'][0]['estimate_quantity']);
        self::assertSame('м3', $result['allocations'][0]['estimate_unit_code']);
        self::assertSame($context->user->id, $result['allocations'][0]['conversion_basis']['confirmed_by_user_id']);
        self::assertEquals($result, $service->replaceAllocations($context->user, $statement, [$row], 'confirmed-conversion', 0));
        $row['conversion_basis']['coefficient'] = '1';
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        $service->replaceAllocations($context->user, $statement, [$row], 'exceeds-contract-basis', 1);
    }
}
