<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeAcceptedAllocationService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeCoverageAcceptanceIntegrityTest extends TestCase
{
    public function test_converted_mapping_cannot_exceed_its_specific_coverage_row_or_accumulate_past_it(): void
    {
        [$actor, $organization] = $this->context();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $this->unit($organization->id, 'м2');
        [$estimate, $item] = $this->estimate($organization->id, $project->id, 'м3', 40);
        [$contract, $contractor] = $this->contract($organization->id, $project->id, $estimate->id, $item->id, 40);
        $statement = $this->statement($actor, $project->id, [
            ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена A', 'unit_code' => 'м2', 'quantity' => '100', 'place' => ['axis' => 'А-1']],
            ['line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Стена B', 'unit_code' => 'м2', 'quantity' => '100', 'place' => ['axis' => 'А-2']],
        ]);
        $coverage = app(WorkVolumeCoverageService::class);
        $lines = $statement->lines->values();
        $rows = [
            $this->coverageRow($lines[0]->id, $contract->id, $estimate->id, $item->id, '50', 'м2', 'м3'),
            $this->coverageRow($lines[1]->id, $contract->id, $estimate->id, $item->id, '100', 'м2', 'м3'),
        ];
        $coverage->replaceAllocations($actor, $statement, $rows, 'integrity-coverage-1', 0);

        $workType = WorkType::query()->create(['organization_id' => $organization->id, 'name' => 'Стена', 'measurement_unit_id' => $this->unit($organization->id, 'м3')->id]);
        $firstLine = $this->actLine($contract, $workType, $actor, 'м3', '16', $item->id, 'integrity-act-1');
        $mapper = app(WorkVolumeAcceptedAllocationService::class);
        try {
            $mapper->mapActLine($actor, $project->id, $firstLine, [['statement_line_id' => $lines[0]->id, 'quantity' => '80']], 'Обмер', 'integrity-map-over-row');
            self::fail('Нельзя сопоставить 80 м² со строкой покрытия 50 м².');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $mapper->mapActLine($actor, $project->id, $firstLine, [['statement_line_id' => $lines[0]->id, 'quantity' => '40']], 'Обмер', 'integrity-map-within-row');
        $remapped = $mapper->mapActLine($actor, $project->id, $firstLine, [['statement_line_id' => $lines[0]->id, 'quantity' => '50']], 'Уточнённый обмер', 'integrity-map-remap', 1);
        self::assertSame('10.000000', (string) $remapped->allocations->first()->source_quantity);
        $secondLine = $this->actLine($contract, $workType, $actor, 'м3', '8', $item->id, 'integrity-act-2');
        try {
            $mapper->mapActLine($actor, $project->id, $secondLine, [['statement_line_id' => $lines[0]->id, 'quantity' => '20']], 'Повторный обмер', 'integrity-map-aggregate-over-row');
            self::fail('Суммарные 60 м² не должны пройти coverage 50 м².');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_unknown_historical_source_item_is_not_silently_assigned_to_a_different_item(): void
    {
        [$actor, $organization] = $this->context();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        [$estimate, $item] = $this->estimate($organization->id, $project->id, 'м2', 100);
        [$contract] = $this->contract($organization->id, $project->id, $estimate->id, $item->id, 100);
        $statement = $this->statement($actor, $project->id, [[
            'line_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'Историческая строка', 'unit_code' => 'м2', 'quantity' => '100', 'place' => ['axis' => 'Б-1'],
        ]]);
        $line = $statement->lines->firstOrFail();
        $workType = WorkType::query()->create(['organization_id' => $organization->id, 'name' => 'Историческая работа', 'measurement_unit_id' => $this->unit($organization->id, 'м2')->id]);
        $sourceLine = $this->actLine($contract, $workType, $actor, 'м2', '80', null, 'integrity-act-unknown-item');
        $mapper = app(WorkVolumeAcceptedAllocationService::class);
        $mapper->mapActLine($actor, $project->id, $sourceLine, [['statement_line_id' => $line->id, 'quantity' => '80']], 'Исторический обмер', 'integrity-map-unknown-item');

        $coverage = app(WorkVolumeCoverageService::class);
        $row = $this->coverageRow($line->id, $contract->id, $estimate->id, $item->id, '100', 'м2', 'м2');
        $coverage->replaceAllocations($actor, $statement, [$row], 'integrity-explicit-item-100', 0);
        self::assertNull(DB::table('performance_act_lines')->where('id', $sourceLine)->value('estimate_item_id'));
        $row['quantity'] = '70';
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        $coverage->replaceAllocations($actor, $statement, [$row], 'integrity-explicit-item-70', 1);
    }

    private function context(): array
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        return [$context->user, $context->organization];
    }

    private function statement(User $actor, int $projectId, array $lines): \App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement
    {
        $service = app(WorkVolumeStatementService::class);
        $statement = $service->createDraft($actor, $projectId, ['lines' => $lines]);
        return $service->approve($actor, $service->submitForReview($actor, $statement, 0), 1);
    }

    private function estimate(int $organizationId, int $projectId, string $unit, int $quantity): array
    {
        $estimate = Estimate::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'number' => 'INTEGRITY-'.uniqid(), 'name' => 'Основание', 'status' => 'draft', 'estimate_date' => '2026-09-20']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа', 'quantity' => $quantity, 'measurement_unit_id' => $this->unit($organizationId, $unit)->id]);
        return [$estimate, $item];
    }

    private function contract(int $organizationId, int $projectId, int $estimateId, int $itemId, int $quantity): array
    {
        $contractor = Contractor::query()->create(['organization_id' => $organizationId, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'contractor_id' => $contractor->id, 'number' => 'INTEGRITY-'.uniqid(), 'date' => '2026-09-20', 'total_amount' => 100000]);
        ContractEstimateItem::query()->create(['contract_id' => $contract->id, 'estimate_id' => $estimateId, 'estimate_item_id' => $itemId, 'quantity' => $quantity]);
        return [$contract, $contractor];
    }

    private function unit(int $organizationId, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->firstOrCreate(['organization_id' => $organizationId, 'short_name' => $shortName], ['name' => $shortName, 'type' => 'work']);
    }

    private function coverageRow(int $lineId, int $contractId, int $estimateId, int $itemId, string $quantity, string $unit, string $targetUnit): array
    {
        return ['statement_line_id' => $lineId, 'contract_id' => $contractId, 'estimate_id' => $estimateId, 'estimate_item_id' => $itemId, 'quantity' => $quantity, 'unit_code' => $unit, 'conversion_basis' => $unit === $targetUnit ? null : ['coefficient' => '0.2', 'reason' => 'Обмер']];
    }

    private function actLine(Contract $contract, WorkType $workType, User $actor, string $unit, string $quantity, ?int $estimateItemId, string $number): int
    {
        $work = CompletedWork::query()->create(['organization_id' => $contract->organization_id, 'project_id' => $contract->project_id, 'contract_id' => $contract->id, 'work_type_id' => $workType->id, 'user_id' => $actor->id, 'estimate_item_id' => $estimateItemId, 'quantity' => $quantity, 'completed_quantity' => $quantity, 'price' => 10, 'total_amount' => 800, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED]);
        $act = ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $contract->project_id, 'act_document_number' => $number, 'act_date' => '2026-09-20', 'amount' => 1000, 'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true]);
        return (int) DB::table('performance_act_lines')->insertGetId(['performance_act_id' => $act->id, 'completed_work_id' => $work->id, 'estimate_item_id' => $estimateItemId, 'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK, 'title' => 'Работа', 'unit' => $unit, 'quantity' => $quantity, 'unit_price' => 10, 'amount' => 800]);
    }
}
