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

    public function test_mobile_can_filter_scopes_and_load_detail_contract(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $scope = $this->createScope($context, 'planned');
        $scope->update(['planned_acceptance_date' => '2026-06-10']);
        $otherScope = $this->createScope($context, 'accepted');
        $otherScope->update(['planned_acceptance_date' => '2026-06-11']);
        $this->allowAccess();

        $checklist = $scope->checklists()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $scope->project_id,
            'title' => 'Чек-лист квартиры',
            'status' => 'active',
        ]);
        $checklist->items()->create([
            'title' => 'Окна проверены',
            'is_required' => true,
            'status' => 'pending',
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
            'title' => 'Фотофиксация',
            'document_type' => 'photo_report',
            'is_required' => true,
            'status' => 'approved',
            'external_url' => 'https://storage.example/report.pdf',
            'approved_at' => now(),
        ]);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/handover-acceptance/scopes?status=planned&planned_from=2026-06-01&planned_to=2026-06-30');

        $listResponse->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $scope->id)
            ->assertJsonPath('data.meta.status', 'planned')
            ->assertJsonPath('data.meta.planned_from', '2026-06-01')
            ->assertJsonPath('data.meta.planned_to', '2026-06-30');

        $this->assertNotSame($otherScope->id, (int) $listResponse->json('data.items.0.id'));

        $detailResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/handover-acceptance/scopes/{$scope->id}");

        $detailResponse->assertOk()
            ->assertJsonPath('data.id', $scope->id)
            ->assertJsonPath('data.planned_acceptance_date', '2026-06-10')
            ->assertJsonPath('data.checklists.0.title', 'Чек-лист квартиры')
            ->assertJsonPath('data.checklists.0.items.0.available_actions.0', 'accept')
            ->assertJsonPath('data.handover_package.documents.0.external_url', 'https://storage.example/report.pdf');
    }

    public function test_mobile_can_review_checklist_items_with_explicit_status(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $scope = $this->createScope($context, 'in_progress');
        $this->allowAccess();

        $checklist = $scope->checklists()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $scope->project_id,
            'title' => 'Чек-лист квартиры',
            'status' => 'active',
        ]);
        $acceptedItem = $checklist->items()->create([
            'title' => 'Окна проверены',
            'is_required' => true,
            'status' => 'pending',
        ]);
        $rejectedItem = $checklist->items()->create([
            'title' => 'Двери проверены',
            'is_required' => true,
            'status' => 'pending',
        ]);

        $missingStatusResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/checklist-items/{$acceptedItem->id}/review");

        $missingStatusResponse->assertStatus(422)
            ->assertJsonPath('message', trans_message('handover_acceptance.errors.validation_failed'))
            ->assertJsonPath('errors.status.0', trans_message('handover_acceptance.validation.checklist_status_required'));

        $missingCommentResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/checklist-items/{$rejectedItem->id}/review", [
                'status' => 'rejected',
            ]);

        $missingCommentResponse->assertStatus(422)
            ->assertJsonPath('errors.comment.0', trans_message('handover_acceptance.validation.checklist_rejection_comment_required'));

        $acceptedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/checklist-items/{$acceptedItem->id}/review", [
                'status' => 'accepted',
            ]);

        $acceptedResponse->assertOk()
            ->assertJsonPath('data.items.0.status', 'accepted');
        $this->assertDatabaseHas('acceptance_checklist_items', [
            'id' => $acceptedItem->id,
            'status' => 'accepted',
        ]);

        $rejectedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/handover-acceptance/checklist-items/{$rejectedItem->id}/review", [
                'status' => 'rejected',
                'comment' => 'Нужно заменить уплотнитель',
            ]);

        $rejectedResponse->assertOk()
            ->assertJsonPath('data.status', 'findings_open')
            ->assertJsonPath('data.items.1.status', 'rejected')
            ->assertJsonPath('data.items.1.comment', 'Нужно заменить уплотнитель');
        $this->assertDatabaseHas('acceptance_checklist_items', [
            'id' => $rejectedItem->id,
            'status' => 'rejected',
            'comment' => 'Нужно заменить уплотнитель',
        ]);
        $this->assertDatabaseHas('acceptance_checklists', [
            'id' => $checklist->id,
            'status' => 'findings_open',
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
