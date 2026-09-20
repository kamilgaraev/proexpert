<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Exceptions\BusinessLogicException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use App\Modules\Core\AccessController;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;

final class WorkVolumeStatementComparisonTest extends TestCase
{
    use \Tests\Support\SubmitsWorkVolumeStatements;

    public function test_comparison_http_route_returns_diff_and_business_validation(): void
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
        $service = $this->app->make(WorkVolumeStatementService::class);
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1']];
        $original = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, ['lines' => [$line]]));
        $revision = $service->createRevision($context->user, $original, ['lines' => [[...$line, 'quantity' => '101']], 'change_reason' => 'Уточнение']);
        $url = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements/'.$original->id.'/compare/';
        $this->withHeaders($context->authHeaders())->getJson($url.$revision->id)
            ->assertOk()->assertJsonPath('data.lines.0.after.quantity', '101.000000');
        $unrelated = $service->createDraft($context->user, $project->id, ['lines' => [$line]]);
        $this->withHeaders($context->authHeaders())->getJson($url.$unrelated->id)->assertStatus(422);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/projects/'.$project->id.'/work-volume-statements/'.$original->id.'/revisions', ['lines' => [$line]])->assertStatus(422);
    }

    public function test_comparison_exposes_revision_reason_and_exact_changed_volume(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $service = $this->app->make(WorkVolumeStatementService::class);
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100.000001', 'place' => ['axis' => 'А-1']];
        $original = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, ['lines' => [$line]]));
        $revised = $service->createRevision($context->user, $original, ['lines' => [[...$line, 'quantity' => '100.000002']], 'change_reason' => 'Уточнение обмера']);

        $comparison = $service->compareRevisions($context->user, $original, $revised);
        self::assertSame(1, $comparison['before']['version']);
        self::assertSame(2, $comparison['after']['version']);
        self::assertSame('Уточнение обмера', $comparison['after']['change_reason']);
        self::assertSame(['quantity'], $comparison['lines'][0]['changed_fields']);
        self::assertSame('100.000001', $comparison['lines'][0]['before']['quantity']);
        self::assertSame('100.000002', $comparison['lines'][0]['after']['quantity']);

        $unrelated = $service->createDraft($context->user, $project->id, ['lines' => [$line]]);
        $this->expectException(BusinessLogicException::class);
        $service->compareRevisions($context->user, $original, $unrelated);
    }
}
