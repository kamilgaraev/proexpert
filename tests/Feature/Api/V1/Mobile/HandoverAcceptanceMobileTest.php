<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class HandoverAcceptanceMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_finding_requires_explicit_severity_and_quality_defect_decision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $session = $this->createSession($context);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/sessions/{$session->id}/findings", [
                'title' => 'Door scratch',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('handover_acceptance.errors.validation_failed'))
            ->assertJsonPath('errors.severity.0', trans_message('handover_acceptance.validation.severity_required'))
            ->assertJsonPath('errors.create_quality_defect.0', trans_message('handover_acceptance.validation.create_quality_defect_required'));
    }

    public function test_mobile_finding_requires_explicit_quality_defect_inspection_decision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $session = $this->createSession($context);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/sessions/{$session->id}/findings", [
                'title' => 'Door scratch',
                'severity' => 'critical',
                'create_quality_defect' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('handover_acceptance.errors.validation_failed'))
            ->assertJsonPath(
                'errors.quality_defect_inspection_required.0',
                trans_message('handover_acceptance.validation.quality_defect_inspection_required')
            );
    }

    public function test_mobile_can_create_resolve_and_send_scope_to_reinspection(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $session = $this->createSession($context);
        $scope = $session->scope()->firstOrFail();
        $this->allowAccess();

        $findingResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/sessions/{$session->id}/findings", [
                'title' => 'Door scratch',
                'description' => 'Repair before handover',
                'severity' => 'critical',
                'create_quality_defect' => true,
                'quality_defect_inspection_required' => false,
            ]);

        $findingResponse->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.severity', 'critical');

        $findingId = (int) $findingResponse->json('data.id');
        $this->assertDatabaseHas('acceptance_findings', [
            'id' => $findingId,
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('quality_defects', [
            'title' => 'Door scratch',
            'severity' => 'critical',
            'inspection_required' => false,
        ]);

        $blockedResolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/findings/{$findingId}/resolve");

        $blockedResolveResponse->assertStatus(422)
            ->assertJsonPath('message', trans_message('handover_acceptance.errors.validation_failed'))
            ->assertJsonPath('errors.resolution_comment.0', trans_message('handover_acceptance.validation.resolution_comment_required'));

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/findings/{$findingId}/resolve", [
                'resolution_comment' => 'Door frame repaired',
            ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $readyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/ready-for-reinspection");

        $readyResponse->assertOk()
            ->assertJsonPath('data.status', 'ready_for_reinspection');
        $this->assertSame('ready_for_reinspection', $scope->fresh()->status);
        $this->assertSame('resolved', AcceptanceFinding::query()->findOrFail($findingId)->status);
    }

    private function createSession(AdminApiTestContext $context): AcceptanceSession
    {
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Apartment handover',
            'status' => 'in_progress',
        ]);

        return AcceptanceSession::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $context->user->id,
            'status' => 'in_progress',
        ]);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
