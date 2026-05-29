<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class QualityDefectControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_show_and_reject_quality_defect_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $foreignDefect = $this->createDefect($foreignContext, $foreignProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Cracked concrete edge',
                'description' => 'Corner edge needs repair before acceptance',
                'severity' => 'major',
                'location_name' => 'Block A / Floor 2',
                'assigned_to' => $assignee->id,
                'due_date' => now()->addDays(3)->toDateString(),
                'inspection_required' => true,
                'schedule_task_id' => 125,
                'photos' => [
                    [
                        'type' => 'before',
                        'url' => 's3://org-' . $context->organization->id . '/quality/before-1.jpg',
                        'caption' => 'Before repair',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.status', QualityDefectStatusEnum::ASSIGNED->value);
        $createResponse->assertJsonPath('data.assigned_user.id', $assignee->id);
        $createResponse->assertJsonPath('data.workflow_summary.status', QualityDefectStatusEnum::ASSIGNED->value);
        $createResponse->assertJsonPath('data.available_actions.0', 'start');
        $this->assertNotEmpty($createResponse->json('data.problem_flags'));

        $defect = QualityDefect::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $defect->organization_id);
        $this->assertSame($project->id, $defect->project_id);
        $this->assertSame($assignee->id, $defect->assigned_to);
        $this->assertSame(1, $defect->photos()->count());
        $this->assertSame(1, $defect->statusHistory()->count());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/quality-control/defects?per_page=20&status=assigned');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($defect->id, $ids);
        $this->assertNotContains($foreignDefect->id, $ids);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/quality-control/defects/{$defect->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $defect->id);
        $showResponse->assertJsonPath('data.project.id', $project->id);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/quality-control/defects/{$foreignDefect->id}");

        $foreignShowResponse->assertNotFound();

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/quality-control/defects/{$defect->id}/reject", [
                'comment' => 'Repair method is not accepted',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', QualityDefectStatusEnum::REJECTED->value);
        $this->assertSame(QualityDefectStatusEnum::REJECTED, $defect->fresh()->status);
    }

    public function test_quality_defect_creation_rejects_foreign_links_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignAssignee = User::factory()->create(['current_organization_id' => $foreignContext->organization->id]);
        $foreignContext->organization->users()->attach($foreignAssignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/quality-control/defects', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project defect',
                'severity' => 'minor',
            ]);

        $foreignProjectResponse->assertStatus(422);

        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $foreignAssigneeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Foreign assignee defect',
                'severity' => 'minor',
                'assigned_to' => $foreignAssignee->id,
            ]);

        $foreignAssigneeResponse->assertStatus(422);

        $this->assertDatabaseMissing('quality_defects', [
            'organization_id' => $context->organization->id,
            'title' => 'Foreign project defect',
        ]);
        $this->assertDatabaseMissing('quality_defects', [
            'organization_id' => $context->organization->id,
            'title' => 'Foreign assignee defect',
        ]);
    }

    public function test_owner_can_create_quality_defect_with_uploaded_photo_in_s3(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Photo quality defect',
                'severity' => 'major',
                'inspection_required' => true,
                'photos' => [
                    [
                        'type' => 'before',
                        'file' => UploadedFile::fake()->image('before.jpg', 1200, 800),
                        'caption' => 'Before repair',
                    ],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.photos.0.type', 'before');

        $path = (string) $response->json('data.photos.0.url');

        $this->assertStringStartsWith("org-{$context->organization->id}/quality-control/defects/", $path);
        Storage::disk('s3')->assertExists($path);
    }

    public function test_defect_requires_result_evidence_before_resolve_when_inspection_required(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $defect = $this->createDefect($context, $project, [
            'status' => QualityDefectStatusEnum::IN_PROGRESS,
            'inspection_required' => true,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $blockedResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/quality-control/defects/{$defect->id}/resolve", [
                'comment' => '',
                'photos' => [],
            ]);

        $blockedResponse->assertStatus(422);
        $this->assertSame(QualityDefectStatusEnum::IN_PROGRESS, $defect->fresh()->status);

        $readyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/quality-control/defects/{$defect->id}/resolve", [
                'comment' => 'Surface repaired and cleaned',
                'photos' => [
                    [
                        'type' => 'after',
                        'url' => 's3://org-' . $context->organization->id . '/quality/after-1.jpg',
                        'caption' => 'After repair',
                    ],
                ],
            ]);

        $readyResponse->assertOk();
        $readyResponse->assertJsonPath('data.status', QualityDefectStatusEnum::READY_FOR_REVIEW->value);
        $this->assertSame(1, $defect->photos()->where('type', 'after')->count());

        $verifyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/quality-control/defects/{$defect->id}/verify", [
                'accepted' => false,
                'comment' => 'Crack is still visible',
            ]);

        $verifyResponse->assertOk();
        $verifyResponse->assertJsonPath('data.status', QualityDefectStatusEnum::REJECTED->value);
        $this->assertSame(QualityDefectStatusEnum::REJECTED, $defect->fresh()->status);
    }

    public function test_assigned_filter_limits_defects_to_selected_responsible_user(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $otherAssignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, ['is_owner' => false, 'is_active' => true, 'settings' => null]);
        $context->organization->users()->attach($otherAssignee->id, ['is_owner' => false, 'is_active' => true, 'settings' => null]);
        $visibleDefect = $this->createDefect($context, $project, ['assigned_to' => $assignee->id]);
        $hiddenDefect = $this->createDefect($context, $project, ['assigned_to' => $otherAssignee->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/quality-control/defects?assigned_to={$assignee->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visibleDefect->id, $ids);
        $this->assertNotContains($hiddenDefect->id, $ids);
    }

    public function test_mobile_foreman_can_create_and_resolve_quality_defect(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Mobile quality defect',
                'severity' => 'critical',
                'location_name' => 'Section 4',
                'inspection_required' => true,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.title', 'Mobile quality defect');

        $defectId = (int) $createResponse->json('data.id');

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/quality-control/defects?project_id={$project->id}");

        $listResponse->assertOk();
        $this->assertContains($defectId, collect($listResponse->json('data.items'))->pluck('id')->all());

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$defectId}/resolve", [
                'comment' => 'Repair completed on site',
            ]);

        $resolveResponse->assertOk();
        $resolveResponse->assertJsonPath('data.status', QualityDefectStatusEnum::READY_FOR_REVIEW->value);
    }

    public function test_customer_can_view_quality_defects_read_only_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'customer_owner');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'customer_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $visibleDefect = $this->createDefect($context, $project);
        $hiddenDefect = $this->createDefect($foreignContext, $foreignProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/customer/quality-control/defects');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visibleDefect->id, $ids);
        $this->assertNotContains($hiddenDefect->id, $ids);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/customer/quality-control/defects/{$visibleDefect->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $visibleDefect->id);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/customer/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Customer mutation attempt',
            ]);

        $createResponse->assertStatus(405);
    }

    private function createDefect(AdminApiTestContext $context, Project $project, array $overrides = []): QualityDefect
    {
        return QualityDefect::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'defect_number' => 'QD-' . $context->organization->id . '-' . uniqid(),
            'title' => 'Existing quality defect',
            'severity' => 'major',
            'status' => QualityDefectStatusEnum::OPEN,
            'inspection_required' => true,
        ], $overrides));
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'quality-control',
                    'project-management',
                    'contract-management',
                    'budget-estimates',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(): void
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
    }
}
