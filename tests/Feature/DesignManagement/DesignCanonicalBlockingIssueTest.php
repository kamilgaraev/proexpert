<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\Rules\OpenBlockingCommentsRule;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Modules\Core\AccessController;
use DomainException;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Models\Project;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignCanonicalBlockingIssueTest extends TestCase
{
    public function test_common_issue_blocks_each_referenced_package_until_accepted(): void
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $attributes = ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id];
        $first = DesignPackage::query()->create($attributes + ['title' => 'АР', 'project_stage' => 'pd', 'status' => 'draft']);
        $second = DesignPackage::query()->create($attributes + ['title' => 'КР', 'project_stage' => 'pd', 'status' => 'draft']);
        $unrelated = DesignPackage::query()->create($attributes + ['title' => 'ОВ', 'project_stage' => 'pd', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create($attributes + ['package_id' => $second->id, 'title' => 'Модель КР', 'artifact_type' => 'model']);
        $version = DesignArtifactVersion::query()->create($attributes + ['artifact_id' => $artifact->id, 'uploaded_by' => $actor->user->id, 'title' => 'КР, редакция 1', 'version_number' => '1', 'source_file_path' => 'test/model.ifc', 'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1, 'file_format' => 'ifc', 'is_current' => true]);
        $issue = QualityDefect::query()->create($attributes + ['kind' => 'project', 'defect_number' => 'PIR-BLOCKING-1', 'title' => 'Проверить сопряжение', 'severity' => 'major', 'status' => 'open', 'metadata' => ['blocking' => ['active' => true], 'design_issue_context' => ['package_id' => $first->id, 'elements' => [['version_id' => $version->id, 'element_id' => 42]]]]]);
        $rule = new OpenBlockingCommentsRule();

        self::assertCount(1, $rule->check($first));
        $findings = $rule->check($second);
        self::assertCount(1, $findings);
        self::assertSame('quality_defect', $findings[0]->targetType);
        self::assertSame($issue->id, $findings[0]->targetId);
        self::assertSame([], $rule->check($unrelated));

        $issue->update(['status' => 'ready_for_review']);
        self::assertCount(1, $rule->check($second));
        $issue->update(['status' => 'resolved']);
        self::assertSame([], $rule->check($first));
        self::assertSame([], $rule->check($second));

        $issue->update(['status' => 'rejected']);
        self::assertCount(1, $rule->check($second));
        $issue = app(QualityDefectService::class)->cancel($issue, $actor->user->id, 'Отменено в интерфейсе качества');
        self::assertTrue($issue->metadata['blocking']['active']);
        self::assertCount(1, $rule->check($second));
        self::assertSame('cancelled', $issue->status->value);
        $project->users()->attach($actor->user->id, ['is_active' => true, 'role' => 'project_manager']);
        $canManageBlocking = false;
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(static function ($user, string $permission) use (&$canManageBlocking): bool {
            return $permission !== 'design-management.issues.manage_blocking' || $canManageBlocking;
        });
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $issues = app(DesignProjectIssueService::class);
        try {
            $issues->setBlocking($issue, $actor->user, false, 'Попытка без отдельного права');
            self::fail('Expected blocking permission denial');
        } catch (DomainException) {
            self::assertTrue($issue->fresh()->metadata['blocking']['active']);
        }
        $canManageBlocking = true;
        try {
            $issues->setBlocking($issue, $actor->user, false, null);
            self::fail('Expected a required removal reason');
        } catch (DomainException) {
            self::assertTrue($issue->fresh()->metadata['blocking']['active']);
        }
        $issue = $issues->setBlocking($issue, $actor->user, false, 'Не препятствует выпуску');
        self::assertFalse($issue->metadata['blocking']['active']);
        self::assertSame([], $rule->check($second));

        $set = DesignModelSet::query()->create($attributes + ['title' => 'Совместная проверка', 'revision' => 1]);
        $revision = DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => 1, 'version_ids' => [$version->id], 'transforms' => [], 'created_by' => $actor->user->id]);
        $issue->update(['metadata' => ['blocking' => ['active' => true], 'design_issue_context' => ['model_set_revision_id' => $revision->id, 'camera' => ['position' => [1, 2, 3]]]]]);
        self::assertCount(1, $rule->check($second));
        self::assertSame([], $rule->check($first));
        $issue->update(['metadata' => ['blocking' => ['active' => true], 'design_issue_context' => [
            'view_models' => [['version_id' => $version->id, 'transform' => ['shift' => [10, 0, 0], 'rotation' => 45]]],
            'camera' => ['position' => [1, 2, 3]],
        ]]]);
        self::assertCount(1, $rule->check($second));
        self::assertSame([], $rule->check($first));
        $issue->update(['kind' => 'construction']);
        self::assertSame([], $rule->check($second));
    }
}
