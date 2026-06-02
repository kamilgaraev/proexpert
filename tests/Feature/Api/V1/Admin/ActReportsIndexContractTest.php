<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Enums\ContractorType;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ActReportsIndexContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_contract_with_filtered_summary(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Contractor',
        ]);
        $contract = $this->createContract($context->organization, $project, $contractor, 'ACT-1');

        $draftAct = $this->createAct($contract, $project, 'KS-2-1', 1000, false);
        $this->createAct($contract, $project, 'KS-2-2', 2500, true);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign contractor',
        ]);
        $foreignContract = $this->createContract($foreignOrganization, $foreignProject, $foreignContractor, 'FOREIGN');
        $this->createAct($foreignContract, $foreignProject, 'KS-2-FOREIGN', 9000, true);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/act-reports?per_page=1&sort_by=amount&sort_direction=asc');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $draftAct->id);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('summary.total_acts', 2);
        $response->assertJsonPath('summary.approved_acts', 1);
        $response->assertJsonPath('summary.total_amount', 3500);

        $draftResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/act-reports?is_approved=false');

        $draftResponse->assertOk();
        $draftResponse->assertJsonPath('meta.total', 1);
        $draftResponse->assertJsonPath('data.0.id', $draftAct->id);
        $draftResponse->assertJsonPath('summary.total_acts', 1);
        $draftResponse->assertJsonPath('summary.approved_acts', 0);
        $draftResponse->assertJsonPath('summary.total_amount', 1000);
    }

    public function test_contractor_organization_can_read_acts_for_owner_contract(): void
    {
        $contractorContext = AdminApiTestContext::create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $ownerOrganization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $ownerOrganization->id,
            'source_organization_id' => $contractorContext->organization->id,
            'name' => 'Connected contractor',
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
        ]);
        $contract = $this->createContract($ownerOrganization, $project, $contractor, 'ACT-CONTRACTOR');
        $act = $this->createAct($contract, $project, 'KS-2-CONTRACTOR', 3000, true);

        $indexResponse = $this->withHeaders($contractorContext->authHeaders())
            ->getJson('/api/v1/admin/act-reports?' . http_build_query([
                'contract_id' => $contract->id,
                'per_page' => 10,
            ]));

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('data.0.id', $act->id);
        $indexResponse->assertJsonPath('summary.total_acts', 1);

        $showResponse = $this->withHeaders($contractorContext->authHeaders())
            ->getJson("/api/v1/admin/act-reports/{$act->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $act->id);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        string $number
    ): Contract {
        return Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => $number,
            'date' => '2026-06-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
    }

    private function createAct(
        Contract $contract,
        Project $project,
        string $number,
        int $amount,
        bool $approved
    ): ContractPerformanceAct {
        return ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => $number,
            'act_date' => '2026-06-10',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'amount' => $amount,
            'status' => $approved ? ContractPerformanceAct::STATUS_APPROVED : ContractPerformanceAct::STATUS_DRAFT,
            'is_approved' => $approved,
        ]);
    }
}
