<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkFinancialImpact;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkReworkFinancialImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_sources_are_scoped_and_legacy_is_not_double_counted(): void
    {
        [$organization, $project, $contract, $work] = $this->fixture();
        $line = $this->quantityLine($organization, $project, $work);
        $signed = $this->act($contract, $project, 'signed', false, 'SIGNED-1');
        $this->canonicalLine($signed, $work, '1.2500', '10.10');
        $this->canonicalLine($signed, $work, '0.7500', '5.20');
        DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $signed->id, 'completed_work_id' => $work->id,
            'included_quantity' => '99.000', 'included_amount' => '999.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacy = $this->act($contract, $project, 'draft', true, 'LEGACY-1');
        DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $legacy->id, 'completed_work_id' => $work->id,
            'included_quantity' => '0.333', 'included_amount' => '3.33',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreignContractor = Contractor::create(['organization_id' => $foreignOrganization->id, 'name' => 'Foreign']);
        $foreignContract = Contract::create([
            'organization_id' => $foreignOrganization->id, 'project_id' => $project->id, 'contractor_id' => $foreignContractor->id,
            'number' => 'FOREIGN-1', 'date' => '2026-09-01', 'subject' => 'Foreign', 'total_amount' => 10, 'status' => 'active',
        ]);
        $foreignAct = $this->act($foreignContract, $project, 'signed', false, 'FOREIGN-1');
        $this->canonicalLine($foreignAct, $work, '50', '500');

        $snapshot = app(WorkReworkFinancialImpact::class)->snapshot($line);

        self::assertTrue($snapshot['correction_review_required']);
        self::assertCount(2, $snapshot['acts']);
        self::assertSame(['SIGNED-1', 'LEGACY-1'], array_column($snapshot['acts'], 'number'));
        self::assertSame('2.0000', $snapshot['acts'][0]['quantity']);
        self::assertSame('15.30', $snapshot['acts'][0]['amount']);
        self::assertSame('canonical', $snapshot['acts'][0]['source']);
        self::assertSame('legacy', $snapshot['acts'][1]['source']);
    }

    public function test_released_act_does_not_require_financial_review(): void
    {
        [$organization, $project, $contract, $work] = $this->fixture();
        $line = $this->quantityLine($organization, $project, $work);
        $this->act($contract, $project, 'rejected', true, 'REJECTED-1');

        self::assertSame(
            ['correction_review_required' => false, 'acts' => []],
            app(WorkReworkFinancialImpact::class)->snapshot($line),
        );
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Contractor']);
        $contract = Contract::create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'CONTRACT-1', 'date' => '2026-09-01', 'subject' => 'Works', 'total_amount' => 1000, 'status' => 'active',
        ]);
        $work = CompletedWork::create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'quantity' => '2', 'completed_quantity' => '2', 'price' => '10', 'total_amount' => '20',
            'completion_date' => '2026-09-01', 'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED, 'status' => CompletedWork::STATUS_CONFIRMED,
        ]);

        return [$organization, $project, $contract, $work];
    }

    private function quantityLine(Organization $organization, Project $project, CompletedWork $work): AcceptanceScopeWorkQuantity
    {
        $unit = MeasurementUnit::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'short_name' => 'шт'],
            ['name' => 'Штука', 'type' => 'work', 'is_default' => false, 'is_system' => false],
        );
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'title' => 'Rework scope', 'status' => 'accepted',
        ]);

        return AcceptanceScopeWorkQuantity::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'acceptance_scope_id' => $scope->id,
            'completed_work_id' => $work->id, 'unit_id' => $unit->id, 'presented_quantity' => '2.000000', 'accepted_quantity' => '1.000000',
            'defect_quantity' => '1.000000', 'defect_reason' => 'Исправление замечания', 'revision' => 1,
        ]);
    }

    private function act(Contract $contract, Project $project, string $status, bool $approved, string $number): ContractPerformanceAct
    {
        return ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => $number,
            'act_date' => '2026-09-10', 'amount' => '100', 'status' => $status, 'is_approved' => $approved,
        ]);
    }

    private function canonicalLine(ContractPerformanceAct $act, CompletedWork $work, string $quantity, string $amount): PerformanceActLine
    {
        return PerformanceActLine::query()->create([
            'performance_act_id' => $act->id, 'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK, 'title' => 'Work',
            'quantity' => $quantity, 'unit_price' => '10', 'amount' => $amount,
        ]);
    }
}
