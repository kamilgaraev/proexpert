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

final class WorkVolumeStatementReviewHttpTest extends TestCase
{
    public function test_review_return_resubmission_and_approval_have_an_explicit_round(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $statement = app(WorkVolumeStatementService::class)->createDraft($context->user, $project->id, ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена',
            'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]]);
        $url = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements/'.$statement->id;
        $this->withHeaders($context->authHeaders());
        $this->postJson($url.'/submit', [])->assertUnprocessable();
        $this->postJson($url.'/approve', ['expected_review_round' => 0])->assertConflict();
        $this->postJson($url.'/submit', ['expected_review_round' => 0])
            ->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.review_round', 1);
        $this->postJson($url.'/return', ['expected_review_round' => 1])->assertUnprocessable();
        $this->postJson($url.'/return', ['expected_review_round' => 1, 'reason' => 'Уточнить место'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review_history.1.reason', 'Уточнить место');
        $this->postJson($url.'/submit', ['expected_review_round' => 0])->assertConflict();
        $this->postJson($url.'/submit', ['expected_review_round' => 1])
            ->assertOk()->assertJsonPath('data.review_round', 2);
        $this->postJson($url.'/approve', ['expected_review_round' => 1])->assertConflict();
        $approved = $this->postJson($url.'/approve', ['expected_review_round' => 2])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonCount(4, 'data.review_history');
        $this->postJson($url.'/approve', ['expected_review_round' => 2])
            ->assertOk()->assertJsonPath('data.approved_at', $approved->json('data.approved_at'))->assertJsonCount(4, 'data.review_history');
    }
}
