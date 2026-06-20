<?php

declare(strict_types=1);

namespace Tests\Unit\Project;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;
use App\Services\Project\ProjectBudgetAmountService;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectParticipantService;
use App\Services\Project\ProjectService;
use App\Services\Project\ProjectTeamService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ProjectServiceSortValidationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function refreshTestDatabase(): void {}

    public function test_projects_for_current_org_fall_back_to_safe_sorting(): void
    {
        $request = Request::create('/projects', 'GET', [
            'sort_by' => 'name desc',
            'sort_direction' => 'up',
        ]);
        $request->attributes->set('current_organization_id', 25);

        $paginator = new LengthAwarePaginator([], 0, 20);
        $projectRepository = Mockery::mock(ProjectRepositoryInterface::class);
        $projectRepository
            ->shouldReceive('getProjectsForOrganizationPaginated')
            ->once()
            ->with(25, 20, [], 'created_at', 'desc')
            ->andReturn($paginator);

        $service = new ProjectService(
            $projectRepository,
            Mockery::mock(UserRepositoryInterface::class),
            Mockery::mock(MaterialRepositoryInterface::class),
            Mockery::mock(WorkTypeRepositoryInterface::class),
            Mockery::mock(LoggingService::class),
            Mockery::mock(OrganizationProfileService::class),
            Mockery::mock(ProjectContextService::class),
            Mockery::mock(OrganizationScopeInterface::class),
            Mockery::mock(ProjectParticipantService::class),
            Mockery::mock(ProjectTeamService::class),
            new ProjectBudgetAmountService
        );

        $this->assertSame($paginator, $service->getProjectsForCurrentOrg($request, 20));
    }
}
