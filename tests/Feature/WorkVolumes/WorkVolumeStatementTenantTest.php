<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementTenantTest extends TestCase
{
    public function test_shared_project_keeps_each_organizations_statements_private(): void
    {
        $owner = AdminApiTestContext::create();
        $participant = AdminApiTestContext::create();
        foreach ([$owner, $participant] as $context) {
            $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        }
        $project = Project::factory()->create(['organization_id' => $owner->organization->id]);
        $project->organizations()->attach($participant->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $service = app(WorkVolumeStatementService::class);
        $payload = ['lines' => [['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '10', 'place' => ['axis' => 'А-1']]]];
        $ownersStatement = $service->createDraft($owner->user, $project->id, $payload);
        $participantsStatement = $service->createDraft($participant->user, $project->id, $payload);
        self::assertSame($owner->organization->id, $ownersStatement->organization_id);
        self::assertSame($participant->organization->id, $participantsStatement->organization_id);
        $url = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements';
        $this->withHeaders($participant->authHeaders())->getJson($url)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $participantsStatement->id);
        $this->withHeaders($participant->authHeaders())->getJson($url.'/'.$ownersStatement->id)->assertNotFound();
        $this->withHeaders($owner->authHeaders())->getJson($url.'/'.$participantsStatement->id)->assertNotFound();
    }
}
