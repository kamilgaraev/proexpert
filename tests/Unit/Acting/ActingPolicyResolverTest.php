<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Models\ActingPolicy;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Acting\ActingPolicyResolver;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class ActingPolicyResolverTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_contract_policy_overrides_organization_policy(): void
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
            'number' => 'ACT-1',
            'date' => '2026-04-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        ActingPolicy::create([
            'organization_id' => $organization->id,
            'mode' => 'operational',
            'allow_manual_lines' => false,
        ]);
        ActingPolicy::create([
            'organization_id' => $organization->id,
            'contract_id' => $contract->id,
            'mode' => 'strict',
            'allow_manual_lines' => true,
        ]);

        $policy = app(ActingPolicyResolver::class)->resolveForContract($contract);

        $this->assertSame('strict', $policy['mode']);
        $this->assertTrue($policy['allow_manual_lines']);
        $this->assertSame('contract', $policy['source']);
    }

    public function test_system_default_is_operational_without_manual_lines(): void
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
            'number' => 'ACT-2',
            'date' => '2026-04-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        $policy = app(ActingPolicyResolver::class)->resolveForContract($contract);

        $this->assertSame('operational', $policy['mode']);
        $this->assertFalse($policy['allow_manual_lines']);
        $this->assertSame('system_default', $policy['source']);
    }
}
