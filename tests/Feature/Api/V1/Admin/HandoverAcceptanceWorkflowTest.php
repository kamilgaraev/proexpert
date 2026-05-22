<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
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

final class HandoverAcceptanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_scope_lifecycle_is_blocked_by_findings_and_required_documents(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $locationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/handover-acceptance/locations', [
                'project_id' => $project->id,
                'name' => 'Tower A',
                'location_type' => 'building',
                'code' => 'A',
            ]);

        $locationResponse->assertCreated();
        $locationId = (int) $locationResponse->json('data.id');

        $roomResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/handover-acceptance/locations', [
                'project_id' => $project->id,
                'parent_id' => $locationId,
                'name' => 'Apartment 21',
                'location_type' => 'room',
                'code' => 'A-21',
            ]);

        $roomResponse->assertCreated();
        $roomId = (int) $roomResponse->json('data.id');

        $scopeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/handover-acceptance/scopes', [
                'project_id' => $project->id,
                'project_location_id' => $roomId,
                'title' => 'Apartment 21 handover',
                'planned_acceptance_date' => '2026-07-01',
            ]);

        $scopeResponse->assertCreated();
        $scopeResponse->assertJsonPath('data.status', 'planned');
        $scopeId = (int) $scopeResponse->json('data.id');

        $checklistResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/checklists", [
                'title' => 'Apartment readiness',
                'items' => [
                    ['title' => 'Walls accepted', 'is_required' => true],
                    ['title' => 'Keys prepared', 'is_required' => true],
                ],
            ]);

        $checklistResponse->assertCreated();
        $checklistResponse->assertJsonCount(2, 'data.items');

        $sessionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/sessions", [
                'scheduled_at' => '2026-07-01 10:00:00',
                'participant_user_ids' => [$context->user->id],
            ]);

        $sessionResponse->assertCreated();
        $sessionId = (int) $sessionResponse->json('data.id');

        $startResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/start");

        $startResponse->assertOk();
        $startResponse->assertJsonPath('data.status', 'in_progress');

        $findingResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/sessions/{$sessionId}/findings", [
                'title' => 'Door frame scratch',
                'description' => 'Repair before customer handover',
                'severity' => 'major',
                'create_quality_defect' => true,
                'quality_defect_inspection_required' => false,
            ]);

        $findingResponse->assertCreated();
        $findingResponse->assertJsonPath('data.status', 'open');
        $findingResponse->assertJsonPath('data.quality_defect.status', 'open');
        $findingId = (int) $findingResponse->json('data.id');
        $this->assertNotNull(AcceptanceFinding::query()->findOrFail($findingId)->quality_defect_id);

        $blockedAcceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/accept", [
                'comment' => 'Looks complete',
            ]);

        $blockedAcceptResponse->assertStatus(422);
        $this->assertSame('findings_open', AcceptanceScope::query()->findOrFail($scopeId)->status);

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/findings/{$findingId}/resolve", [
                'resolution_comment' => 'Door frame repaired',
            ]);

        $resolveResponse->assertOk();
        $resolveResponse->assertJsonPath('data.status', 'resolved');

        $readyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/ready-for-reinspection");

        $readyResponse->assertOk();
        $readyResponse->assertJsonPath('data.status', 'ready_for_reinspection');

        $acceptResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/accept", [
                'comment' => 'Accepted after repair',
            ]);

        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('data.status', 'accepted');

        $packageResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/package", [
                'title' => 'Customer handover package',
                'documents' => [
                    [
                        'title' => 'Executive package',
                        'document_type' => 'executive_document',
                        'is_required' => true,
                        'status' => 'missing',
                    ],
                ],
            ]);

        $packageResponse->assertCreated();

        $blockedHandoverResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/handover");

        $blockedHandoverResponse->assertStatus(422);

        $documentId = (int) $packageResponse->json('data.documents.0.id');
        $approveDocumentResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/package-documents/{$documentId}/approve", [
                'external_url' => 's3://org-' . $context->organization->id . '/handover/executive.pdf',
            ]);

        $approveDocumentResponse->assertOk();
        $approveDocumentResponse->assertJsonPath('data.status', 'approved');

        $handoverResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/handover");

        $handoverResponse->assertOk();
        $handoverResponse->assertJsonPath('data.status', 'handed_over');

        $reopenResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/handover-acceptance/scopes/{$scopeId}/reopen", [
                'reason' => 'Customer found new scratch',
            ]);

        $reopenResponse->assertOk();
        $reopenResponse->assertJsonPath('data.status', 'reopened');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scopeId,
            'status' => 'accepted',
            'comment' => 'Accepted after repair',
        ]);
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scopeId,
            'status' => 'reopened',
            'comment' => 'Customer found new scratch',
        ]);
    }

    public function test_customer_sees_only_accessible_project_acceptance_scopes(): void
    {
        $this->withoutMiddleware();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $customer = User::factory()->create([
            'current_organization_id' => $context->organization->id,
            'email_verified_at' => now(),
        ]);
        $context->organization->users()->attach($customer->id, ['is_owner' => false, 'is_active' => true]);
        $project->users()->attach($customer->id, ['role' => 'customer']);
        $this->allowAccess();

        $visibleScope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Visible handover',
            'status' => 'accepted',
        ]);
        AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $foreignProject->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Hidden handover',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($customer, 'api_landing')
            ->getJson('/api/v1/customer/handover-acceptance/scopes');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visibleScope->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_customer_can_sign_accessible_accepted_scope_and_reject_with_reason(): void
    {
        $this->withoutMiddleware();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $customer = User::factory()->create([
            'current_organization_id' => $context->organization->id,
            'email_verified_at' => now(),
        ]);
        $context->organization->users()->attach($customer->id, ['is_owner' => false, 'is_active' => true]);
        $project->users()->attach($customer->id, ['role' => 'customer']);
        $this->allowAccess();

        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Customer signable handover',
            'status' => 'accepted',
        ]);
        $package = HandoverPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Customer handover package',
            'status' => 'draft',
        ]);
        $package->documents()->create([
            'title' => 'Executive package',
            'document_type' => 'executive_document',
            'is_required' => true,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $foreignScope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $foreignProject->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Foreign handover',
            'status' => 'accepted',
        ]);

        $handoverResponse = $this->actingAs($customer, 'api_landing')
            ->postJson("/api/v1/customer/handover-acceptance/scopes/{$scope->id}/handover");

        $handoverResponse->assertOk();
        $handoverResponse->assertJsonPath('data.status', 'handed_over');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $customer->id,
            'status' => 'handed_over',
        ]);

        $rejectResponse = $this->actingAs($customer, 'api_landing')
            ->postJson("/api/v1/customer/handover-acceptance/scopes/{$scope->id}/reject", [
                'reason' => 'Нужно проверить акт передачи ключей',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', 'reopened');
        $this->assertDatabaseHas('acceptance_signoffs', [
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $customer->id,
            'status' => 'reopened',
            'comment' => 'Нужно проверить акт передачи ключей',
        ]);

        $foreignResponse = $this->actingAs($customer, 'api_landing')
            ->postJson("/api/v1/customer/handover-acceptance/scopes/{$foreignScope->id}/handover");

        $foreignResponse->assertForbidden();
    }

    private function allowAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });

        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
    }
}
