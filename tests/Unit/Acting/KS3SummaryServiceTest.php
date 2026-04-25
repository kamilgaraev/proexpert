<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Acting\KS3SummaryService;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class KS3SummaryServiceTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_summary_splits_previous_current_and_cumulative_approved_amounts(): void
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
            'number' => 'KS3-1',
            'date' => '2026-04-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'prev',
            'act_date' => '2026-03-30',
            'amount' => 1000,
            'is_approved' => true,
        ]);
        ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'current',
            'act_date' => '2026-04-15',
            'amount' => 2000,
            'is_approved' => true,
        ]);
        ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'draft',
            'act_date' => '2026-04-20',
            'amount' => 3000,
            'is_approved' => false,
        ]);

        $summary = app(KS3SummaryService::class)->summarize(
            $contract->id,
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertSame(1000.0, $summary['previous_approved_amount']);
        $this->assertSame(2000.0, $summary['current_approved_amount']);
        $this->assertSame(3000.0, $summary['cumulative_approved_amount']);
    }
}
