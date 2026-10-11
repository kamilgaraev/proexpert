<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentApprovedListService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentApprovedListsTest extends TestCase
{
    public function test_active_participant_reads_project_owner_lists_in_revision_order(): void
    {
        $context = AdminApiTestContext::create();
        $owner = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $project->organizations()->attach($context->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $this->allowAccess($context);
        $first = $this->createList($project, $context->user, 1);
        $second = $this->createList($project, $context->user, 2);
        $otherProject = Project::factory()->create(['organization_id' => $owner->id]);
        $this->createList($otherProject, $context->user, 1);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/projects/{$project->id}/approved-lists");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.approved_at', '2026-10-11');
        $response->assertJsonPath('data.0.items.0.key', 'protocol');
    }

    public function test_owner_receives_empty_list_when_no_revisions_exist(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $this->allowAccess($context);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/projects/{$project->id}/approved-lists")
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_foreign_and_inactive_participants_receive_not_found(): void
    {
        $context = AdminApiTestContext::create();
        $owner = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $this->allowAccess($context);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/projects/{$project->id}/approved-lists")
            ->assertNotFound();

        $project->organizations()->attach($context->organization->id, ['role' => 'contractor', 'is_active' => false]);
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/projects/{$project->id}/approved-lists")
            ->assertNotFound();
    }

    public function test_service_enforces_permission_outside_http(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $this->mock(AuthorizationService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(false);
        });

        try {
            app(ExecutiveDocumentApprovedListService::class)->forProject($project->id, $context->user);
            $this->fail('Permission denial expected');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function createList(Project $project, User $user, int $revision): ExecutiveDocumentApprovedList
    {
        return ExecutiveDocumentApprovedList::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'revision' => $revision,
            'approved_by_party' => 'Технический заказчик',
            'approved_at' => '2026-10-11',
            'file_url' => "org-{$project->organization_id}/approved-list-{$revision}.pdf",
            'file_hash' => hash('sha256', (string) $revision),
            'original_name' => 'approved-list.pdf',
            'items' => [['key' => 'protocol', 'profile_type' => 'system_test_act', 'title' => 'Протокол испытаний', 'stage' => 'document_review']],
            'uploaded_by' => $user->id,
        ]);
    }

    private function allowAccess(AdminApiTestContext $context): void
    {
        $this->mock(AccessController::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, static function (MockInterface $mock) use ($context): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->withArgs(static fn (User $user, string $permission, array $scope = []): bool =>
                ! str_starts_with($permission, 'executive-documentation.')
                || ! isset($scope['strict_project_scope'])
                || ($scope['organization_id'] ?? null) === $context->organization->id
            )->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()
                    ->where('is_active', true)
                    ->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))
                    ->get()
            );
        });
    }
}
