<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Exceptions\BusinessLogicException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementAccessTest extends TestCase
{
    public function test_inactive_membership_does_not_allow_reading_statements(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();

        $this->expectException(BusinessLogicException::class);
        $this->app->make(WorkVolumeStatementService::class)->assertProjectAccess($context->user, $project->id);
    }

    public function test_current_organization_must_match_the_statement_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->forceFill(['current_organization_id' => Organization::factory()->create()->id])->save();
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();

        $this->expectException(BusinessLogicException::class);
        $this->app->make(WorkVolumeStatementService::class)->assertProjectAccess($context->user, $project->id);
    }

    public function test_assigned_project_restriction_applies_even_with_module_permission(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'assigned_projects']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();

        $this->expectException(BusinessLogicException::class);
        $this->app->make(WorkVolumeStatementService::class)->assertProjectAccess($context->user, $project->id);
    }

    public function test_authorization_receives_strict_project_scope(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->once()->with(
            $context->user,
            'budget-estimates.view',
            ['project_id' => $project->id, 'organization_id' => $context->organization->id, 'strict_project_scope' => true],
        )->andReturnTrue();

        $this->app->make(WorkVolumeStatementService::class)->assertProjectAccess($context->user, $project->id);
    }
}
