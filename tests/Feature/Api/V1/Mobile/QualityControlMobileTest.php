<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class QualityControlMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_quality_defect_requires_explicit_severity_and_inspection_decision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Mobile quality defect',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('quality_control.errors.validation_failed'))
            ->assertJsonPath('errors.severity.0', trans_message('quality_control.validation.severity_required'))
            ->assertJsonPath('errors.inspection_required.0', trans_message('quality_control.validation.inspection_required'));

        $this->assertDatabaseMissing('quality_defects', [
            'organization_id' => $context->organization->id,
            'title' => 'Mobile quality defect',
        ]);
    }

    public function test_mobile_quality_defect_lifecycle_uses_explicit_inspection_decision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/quality-control/defects', [
                'project_id' => $project->id,
                'title' => 'Mobile quality defect',
                'severity' => 'critical',
                'location_name' => 'Section 4',
                'inspection_required' => false,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.title', 'Mobile quality defect')
            ->assertJsonPath('data.inspection_required', false);

        $defectId = (int) $createResponse->json('data.id');

        $startResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$defectId}/start", [
                'comment' => 'Работы начаты на объекте',
            ]);

        $startResponse->assertOk()
            ->assertJsonPath('data.status', QualityDefectStatusEnum::IN_PROGRESS->value);
        $this->assertDatabaseHas('quality_defect_status_history', [
            'quality_defect_id' => $defectId,
            'to_status' => QualityDefectStatusEnum::IN_PROGRESS->value,
            'comment' => 'Работы начаты на объекте',
        ]);

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$defectId}/resolve");

        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', QualityDefectStatusEnum::READY_FOR_REVIEW->value);

        $defect = QualityDefect::query()->findOrFail($defectId);
        $this->assertFalse((bool) $defect->inspection_required);
        $this->assertSame(QualityDefectStatusEnum::READY_FOR_REVIEW, $defect->status);
    }

    public function test_mobile_quality_defect_filters_are_validated_and_applied(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $openCritical = $this->createDefect($context, $project, 'open', 'critical', [
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $openMajor = $this->createDefect($context, $project, 'open', 'major', [
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $resolvedCritical = $this->createDefect($context, $project, 'resolved', 'critical', [
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $filteredResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/quality-control/defects?status=open&severity=critical&overdue=1&sort_by=due_date&sort_dir=asc');

        $filteredResponse->assertOk();
        $filteredIds = collect($filteredResponse->json('data.items'))->pluck('id')->all();
        $this->assertSame([$openCritical->id], $filteredIds);

        $notOverdueResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/quality-control/defects?overdue=0&per_page=50');

        $notOverdueResponse->assertOk();
        $allIds = collect($notOverdueResponse->json('data.items'))->pluck('id')->all();
        $this->assertContains($openCritical->id, $allIds);
        $this->assertContains($openMajor->id, $allIds);
        $this->assertContains($resolvedCritical->id, $allIds);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/quality-control/defects?status=unknown')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', trans_message('quality_control.validation.status_invalid'));
    }

    public function test_mobile_quality_defect_can_upload_result_photo_verify_and_reject(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('upload')
                ->once()
                ->andReturn('https://storage.example/quality/result.jpg');
        });

        $defect = $this->createDefect($context, $project, 'in_progress', 'critical', [
            'inspection_required' => true,
        ]);

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/mobile/quality-control/defects/{$defect->id}/resolve", [
                'photos' => [
                    [
                        'type' => 'after',
                        'file' => UploadedFile::fake()->image('result.jpg'),
                    ],
                ],
            ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', QualityDefectStatusEnum::READY_FOR_REVIEW->value)
            ->assertJsonPath('data.photos.0.url', 'https://storage.example/quality/result.jpg');
        $this->assertDatabaseHas('quality_defect_photos', [
            'quality_defect_id' => $defect->id,
            'type' => 'after',
            'url' => 'https://storage.example/quality/result.jpg',
        ]);

        $verifyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$defect->id}/verify", [
                'comment' => 'Результат проверен на объекте',
            ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.status', QualityDefectStatusEnum::RESOLVED->value);
        $this->assertDatabaseHas('quality_defect_status_history', [
            'quality_defect_id' => $defect->id,
            'to_status' => QualityDefectStatusEnum::RESOLVED->value,
            'comment' => 'Результат проверен на объекте',
        ]);

        $rejectedDefect = $this->createDefect($context, $project, 'ready_for_review', 'major');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$rejectedDefect->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('errors.comment.0', trans_message('quality_control.validation.comment_required'));

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/quality-control/defects/{$rejectedDefect->id}/reject", [
                'comment' => 'Нужно переделать примыкание',
            ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('data.status', QualityDefectStatusEnum::REJECTED->value);
        $this->assertDatabaseHas('quality_defect_status_history', [
            'quality_defect_id' => $rejectedDefect->id,
            'to_status' => QualityDefectStatusEnum::REJECTED->value,
            'comment' => 'Нужно переделать примыкание',
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

    private function createDefect(
        AdminApiTestContext $context,
        Project $project,
        string $status,
        string $severity,
        array $attributes = []
    ): QualityDefect {
        return QualityDefect::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'defect_number' => 'QD-' . uniqid(),
            'title' => 'Mobile quality defect',
            'severity' => $severity,
            'status' => $status,
            'inspection_required' => true,
        ], $attributes));
    }
}
