<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\Acting\CompletedWorkActEligibilityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

final class CompletedWorkActEligibilityTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_confirmed_manual_and_journal_production_facts_are_eligible(): void
    {
        [$contract, $project, $organization] = $this->createContract();

        $manual = $this->createWork($organization->id, $project->id, $contract->id, [
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
        ]);
        $journal = $this->createWork($organization->id, $project->id, $contract->id, [
            'journal_entry_id' => 101,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
        ]);
        $draft = $this->createWork($organization->id, $project->id, $contract->id, [
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'status' => CompletedWork::STATUS_DRAFT,
        ]);
        $resource = $this->createWork($organization->id, $project->id, $contract->id, [
            'journal_entry_id' => 102,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'additional_info' => ['fact_kind' => 'material'],
        ]);

        $eligible = app(CompletedWorkActEligibilityService::class)
            ->query($contract->id, '2026-04-01', '2026-04-30')
            ->orderBy('id')
            ->get();

        self::assertSame([$manual->id, $journal->id], $eligible->modelKeys());
        self::assertNotContains($draft->id, $eligible->modelKeys());
        self::assertNotContains($resource->id, $eligible->modelKeys());
    }

    public function test_requires_schedule_fact_is_returned_as_blocked(): void
    {
        [$contract, $project, $organization] = $this->createContract();
        $work = $this->createWork($organization->id, $project->id, $contract->id, [
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);

        $blocked = app(ActingAvailabilityService::class)->getBlockedWorks(
            $contract->id,
            '2026-04-01',
            '2026-04-30',
        );

        self::assertSame($work->id, $blocked[0]['id']);
        self::assertContains('schedule_missing', array_column($blocked[0]['blockers'], 'code'));
    }

    public function test_same_work_is_not_reserved_twice_for_canonical_and_legacy_links(): void
    {
        [$contract, $project, $organization] = $this->createContract();
        $work = $this->createWork($organization->id, $project->id, $contract->id);
        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'ACT-1',
            'act_date' => '2026-04-15',
            'amount' => 100,
            'status' => ContractPerformanceAct::STATUS_DRAFT,
            'is_approved' => false,
        ]);

        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Бетонирование',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);
        DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'included_quantity' => 1,
            'included_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $available = DB::transaction(
            fn (): array => app(ActingQuantityReservationService::class)
                ->availableQuantities(collect([$work]))
        );

        self::assertSame(10000, $available[$work->id]);
    }

    private function createWork(int $organizationId, int $projectId, int $contractId, array $attributes = []): CompletedWork
    {
        return CompletedWork::create(array_merge([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'quantity' => 2,
            'completed_quantity' => 2,
            'price' => 100,
            'total_amount' => 200,
            'completion_date' => '2026-04-10',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'status' => CompletedWork::STATUS_CONFIRMED,
        ], $attributes));
    }

    private function createContract(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ELIGIBILITY-1',
            'date' => '2026-04-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        return [$contract, $project, $organization];
    }
}
