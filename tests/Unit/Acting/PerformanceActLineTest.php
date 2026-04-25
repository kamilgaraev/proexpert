<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class PerformanceActLineTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_manual_line_requires_reason_when_policy_requires_it(): void
    {
        $this->expectException(BusinessLogicException::class);

        $line = new PerformanceActLine([
            'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => 'Additional work',
            'quantity' => 1,
            'unit_price' => 5000,
            'amount' => 5000,
        ]);

        $line->assertManualLineAllowed([
            'allow_manual_lines' => true,
            'require_manual_line_reason' => true,
        ]);
    }

    public function test_manual_line_reason_is_optional_when_policy_allows_it(): void
    {
        $act = $this->createAct();

        $line = new PerformanceActLine([
            'performance_act_id' => $act->id,
            'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => 'Additional work',
            'quantity' => 1,
            'unit_price' => 5000,
            'amount' => 5000,
        ]);

        $line->assertManualLineAllowed([
            'allow_manual_lines' => true,
            'require_manual_line_reason' => false,
        ]);
        $line->save();

        $this->assertDatabaseHas('performance_act_lines', [
            'performance_act_id' => $act->id,
            'line_type' => PerformanceActLine::TYPE_MANUAL,
            'manual_reason' => null,
        ]);
    }

    public function test_manual_line_is_rejected_when_policy_disallows_it(): void
    {
        $line = new PerformanceActLine([
            'line_type' => PerformanceActLine::TYPE_MANUAL,
            'title' => 'Additional work',
            'quantity' => 1,
            'unit_price' => 5000,
            'amount' => 5000,
            'manual_reason' => 'Accepted outside journal',
        ]);

        $this->expectException(BusinessLogicException::class);

        $line->assertManualLineAllowed([
            'allow_manual_lines' => false,
        ]);
    }

    private function createAct(): ContractPerformanceAct
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Contractor',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-LINE',
            'date' => '2026-04-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        return ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => '1',
            'act_date' => '2026-04-15',
            'amount' => 0,
            'is_approved' => false,
        ]);
    }
}
