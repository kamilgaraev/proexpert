<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantDomainToolsTest extends TestCase
{
    private AssistantDomainReadService $reader;
    private bool $permissions = true;
    private bool $financialPermissions = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions && ($this->financialPermissions || ! in_array($permission, ['budget-estimates.finance.view','finance.view'], true)));
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'budget-estimates']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService(), $entitlements);
        $this->reader = new AssistantDomainReadService(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy, $authorization);
    }

    public function test_private_projects_before_visible_match_do_not_starve_search_limit(): void
    {
        [$organization, $actor] = $this->actor();
        for ($index = 0; $index < 25; $index++) {
            Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Стройка скрытая '.$index, 'is_archived' => false]);
        }
        $visible = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Стройка доступная', 'is_archived' => false]);
        $actor->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $result = $this->reader->execute('search', ['domain' => 'projects', 'entity_type' => 'project', 'query' => 'Стройка', 'limit' => 1, 'fields' => ['id','name']], $actor, $organization->id);
        $this->assertCount(1, $result['results']);
        $this->assertSame($visible->id, $result['results'][0]['id']);
        $this->assertSame('/projects/'.$visible->id, $result['results'][0]['navigation']['url']);
    }

    public function test_money_fields_are_removed_without_financial_permission(): void
    {
        [$organization, $actor] = $this->actor();
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'PRIVATE-PRICE', 'name' => 'Смета', 'estimate_date' => now()->toDateString(), 'total_amount' => 12345]));
        $result = $this->reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id, 'fields' => ['id','name','total_amount']], $actor, $organization->id);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Смета', $result['results'][0]['fields']['name']);
        $this->assertArrayNotHasKey('total_amount', $result['results'][0]['fields']);
        $this->assertSame('structured', $result['source_refs'][0]['content_scope']);
        $this->assertSame(['id', 'name'], $result['source_refs'][0]['checked_fields']);
        $this->assertNotContains('budget-estimates.finance.view', $result['source_refs'][0]['required_permissions']);
        $this->assertSame(['estimates'], $result['source_refs'][0]['required_domains']);
        $this->assertSame($result['fetched_at'], $result['source_refs'][0]['fetched_at']);
        $this->assertNotEmpty($result['source_refs'][0]['source_version']);
    }

    public function test_numeric_evidence_references_require_permission_for_returned_money_fields(): void
    {
        [$organization, $actor] = $this->actor();
        $this->financialPermissions = true;
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'LIVE-PRICE', 'name' => 'Смета', 'estimate_date' => now()->toDateString(), 'total_amount' => 12345]));
        $result = $this->reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id, 'fields' => ['id', 'name', 'total_amount']], $actor, $organization->id);
        $reference = $result['financial_evidence']['source_refs'][0];
        $this->assertSame($result['source_refs'][0], $reference);
        $this->assertContains('budget-estimates.finance.view', $reference['required_permissions']);
        $this->assertContains('total_amount', $reference['checked_fields']);
        $this->assertSame($result['fetched_at'], $reference['fetched_at']);
        $this->assertSame('12345.00', $result['financial_evidence']['rows'][0]['fields']['total_amount']);
        $navigation = $this->reader->execute('navigation', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id], $actor, $organization->id);
        $this->assertSame(['id'], $navigation['source_refs'][0]['checked_fields']);
        $this->assertNotContains('budget-estimates.finance.view', $navigation['source_refs'][0]['required_permissions']);
    }

    public function test_navigation_rechecks_revoked_permissions(): void
    {
        [$organization, $actor] = $this->actor();
        $this->permissions = false;
        $this->expectException(AccessDeniedHttpException::class);
        $this->reader->execute('navigation', ['domain' => 'projects', 'entity_type' => 'project', 'id' => 1], $actor, $organization->id);
    }

    private function actor(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        return [$organization, $actor];
    }
}
