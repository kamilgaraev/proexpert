<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceHiringOfferService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\Mobile\MobileTeamExpansionService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class MobileTeamExpansionProjectScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        DB::statement('CREATE TEMP TABLE projects (id BIGINT PRIMARY KEY, organization_id BIGINT NOT NULL, name VARCHAR NOT NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE brigade_requests (id BIGINT PRIMARY KEY, contractor_organization_id BIGINT NOT NULL, project_id BIGINT NOT NULL, title VARCHAR NOT NULL, status VARCHAR NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ON COMMIT DROP');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_lists_and_mutations_are_limited_to_accessible_projects(): void
    {
        DB::table('projects')->insert([
            ['id' => 10, 'organization_id' => 1, 'name' => 'Accessible'],
            ['id' => 99, 'organization_id' => 1, 'name' => 'Hidden'],
        ]);
        DB::table('brigade_requests')->insert([
            ['id' => 1, 'contractor_organization_id' => 1, 'project_id' => 10, 'title' => 'Visible', 'status' => 'open'],
            ['id' => 2, 'contractor_organization_id' => 1, 'project_id' => 99, 'title' => 'Hidden', 'status' => 'open'],
        ]);

        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);
        $actor->shouldReceive('belongsToOrganization')->with(1)->andReturn(true);
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->with(1, 'brigades')->andReturn(true);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(
            static fn (User $user, string $permission, array $context): bool =>
                ($context['project_id'] ?? null) === 10
                && ($context['strict_project_scope'] ?? false) === true
        );
        $userProjects = Mockery::mock(UserProjectAccessService::class);
        $userProjects->shouldReceive('queryAccessibleProjects')->andReturnUsing(
            static fn (User $user, int $organizationId) => Project::query()
                ->where('projects.organization_id', $organizationId)
                ->whereIn('projects.id', [10])
        );
        $service = new MobileTeamExpansionService(
            $authorization,
            $access,
            Mockery::mock(MarketplaceSearchService::class),
            Mockery::mock(MarketplaceHiringOfferService::class),
            Mockery::mock(BrigadeWorkflowService::class),
            new MobileProjectAccessResolver($userProjects),
        );

        $page = $service->brigadeRequests($actor, 1, [], 20);
        self::assertSame(1, $page->total());
        self::assertSame(1, $page->items()[0]->id);

        try {
            $service->brigadeRequests($actor, 1, ['project_id' => 99], 20);
            self::fail('Hidden project was accepted by the list');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }

        try {
            $service->createBrigadeRequest($actor, 1, ['project_id' => 99, 'title' => 'Invalid']);
            self::fail('Hidden project was accepted by the create action');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }
}
