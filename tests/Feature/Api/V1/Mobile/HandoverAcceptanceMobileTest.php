<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
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

    public function test_mobile_can_start_accept_handover_and_reopen_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $scope = $this->createScope($context, 'planned');
        $this->allowAccess();

        $startResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/start");

        $startResponse->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $acceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/accept", [
                'comment' => 'Осмотр выполнен без замечаний',
            ]);

        $acceptResponse->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $context->user->id,
            'status' => 'accepted',
            'comment' => 'Осмотр выполнен без замечаний',
        ]);

        $package = HandoverPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Комплект передачи',
            'status' => 'draft',
        ]);
        $package->documents()->create([
            'title' => 'Исполнительная документация',
            'document_type' => 'executive_document',
            'is_required' => true,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $handoverResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/handover");

        $handoverResponse->assertOk()
            ->assertJsonPath('data.status', 'handed_over');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $context->user->id,
            'status' => 'handed_over',
        ]);

        $reopenResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/reopen", [
                'reason' => 'Нужно обновить комплект документов',
            ]);

        $reopenResponse->assertOk()
            ->assertJsonPath('data.status', 'reopened');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $context->user->id,
            'status' => 'reopened',
            'comment' => 'Нужно обновить комплект документов',
        ]);
    }

    public function test_mobile_can_reject_scope_with_required_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $scope = $this->createScope($context, 'in_progress');
        $this->allowAccess();

        $blockedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/reject");

        $blockedResponse->assertStatus(422)
            ->assertJsonPath('message', trans_message('handover_acceptance.errors.validation_failed'))
            ->assertJsonPath('errors.reason.0', trans_message('handover_acceptance.validation.reason_required'));

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}/reject", [
                'reason' => 'Есть замечания заказчика',
            ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $context->user->id,
            'status' => 'rejected',
            'comment' => 'Есть замечания заказчика',
        ]);
    }

    private function createSession(AdminApiTestContext $context): AcceptanceSession
    {
        $scope = $this->createScope($context, 'in_progress');

        return AcceptanceSession::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $context->user->id,
            'status' => 'in_progress',
        ]);
    }

    private function createScope(AdminApiTestContext $context, string $status): AcceptanceScope
    {
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        return AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Apartment handover',
            'status' => $status,
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
