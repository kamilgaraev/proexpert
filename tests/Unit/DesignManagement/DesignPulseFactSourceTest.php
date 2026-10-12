<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignPulseFactSource;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\TestCase;

final class DesignPulseFactSourceTest extends TestCase
{
    public function test_collects_design_management_risks(): void
    {
        $actor = $this->actor();
        $organization = $actor->organization;
        $project = $this->project($actor);
        $package = $this->createPackage($organization->id, $project->id, [
            'planned_issue_date' => '2026-05-30',
            'status' => 'in_work',
        ]);
        $version = $this->createModelVersion($package);

        DesignModelDerivative::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'status' => 'failed',
            'failed_reason' => 'conversion failed',
            'metadata' => [],
        ]);

        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadEntity($actor->user, (int) $organization->id, 'design_artifact_version', $version->id));
        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id, $actor->user->id));
        $types = $facts->pluck('type')->all();

        $this->assertContains('design_model_derivative_missing', $types);
        $this->assertContains('design_package_overdue', $types);
        $this->assertContains('design_model_preparation_failed', $types);
        $this->assertSame('design_management', $facts->first()->source);
    }

    public function test_ready_derivative_removes_missing_viewer_risk(): void
    {
        $actor = $this->actor();
        $organization = $actor->organization;
        $project = $this->project($actor);
        $package = $this->createPackage($organization->id, $project->id);
        $version = $this->createModelVersion($package);

        DesignModelDerivative::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => 'org-' . $organization->id . '/pir/model.frag',
            'status' => 'ready',
            'metadata' => DesignViewerConverter::preparedMetadata(),
        ]);

        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id, $actor->user->id));

        $this->assertNotContains('design_model_derivative_missing', $facts->pluck('type')->all());
    }

    public function test_inactive_module_returns_no_design_facts(): void
    {
        $actor = AdminApiTestContext::create(userAttributes: ['is_active' => true], roleSlug: 'project_manager');
        $organization = $actor->organization;
        $project = $this->project($actor);
        $package = $this->createPackage($organization->id, $project->id);
        $this->createModelVersion($package);

        self::assertFalse(app(AccessController::class)->hasModuleAccess((int) $organization->id, 'design-management'));
        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id, $actor->user->id));

        $this->assertCount(0, $facts);
    }

    public function test_context_without_an_actor_cannot_collect_design_facts(): void
    {
        $actor = $this->actor();
        $project = $this->project($actor);
        $package = $this->createPackage($actor->organization->id, $project->id);
        $this->createModelVersion($package);

        self::assertCount(0, app(DesignPulseFactSource::class)->collect($this->context($actor->organization->id, $project->id, null)));
    }

    private function createPackage(int $organizationId, int $projectId, array $overrides = []): DesignPackage
    {
        return DesignPackage::query()->create(array_merge([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'title' => 'Раздел АР',
            'status' => 'draft',
            'metadata' => [],
        ], $overrides));
    }

    private function createModelVersion(DesignPackage $package): DesignArtifactVersion
    {
        $artifact = DesignArtifact::query()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'package_id' => $package->id,
            'artifact_type' => 'model',
            'title' => 'Архитектурная модель',
            'status' => 'active',
            'metadata' => [],
        ]);

        return DesignArtifactVersion::query()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'artifact_id' => $artifact->id,
            'title' => 'Архитектурная модель',
            'version_number' => '1',
            'source_format' => 'ifc',
            'source_file_path' => 'org-' . $package->organization_id . '/pir/model.ifc',
            'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 1024,
            'status' => 'uploaded',
            'is_current' => true,
            'metadata' => [],
        ]);
    }

    private function context(int $organizationId, int $projectId, ?int $userId): ProjectPulseContext
    {
        $date = CarbonImmutable::parse('2026-06-01')->startOfDay();

        return new ProjectPulseContext(
            organizationId: $organizationId,
            projectId: $projectId,
            period: 'today',
            date: $date,
            from: $date->startOfDay(),
            to: $date->endOfDay(),
            useAi: false,
            userId: $userId,
        );
    }

    private function actor(): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(userAttributes: ['is_active' => true], roleSlug: 'project_manager');
        DesignManagementTestEntitlements::activate($actor);
        Cache::store('array')->flush();
        self::assertTrue(app(AccessController::class)->hasModuleAccess((int) $actor->organization->id, 'design-management'));
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadDomain($actor->user, (int) $actor->organization->id, 'design'));
        foreach (['projects.view', 'design-management.models.view'] as $permission) {
            self::assertTrue(app(AuthorizationService::class)->canCurrent($actor->user, $permission, ['organization_id' => (int) $actor->organization->id]));
        }

        return $actor;
    }

    private function project(AdminApiTestContext $actor): Project
    {
        self::assertTrue($actor->user->is_active);
        self::assertSame((int) $actor->organization->id, (int) $actor->user->current_organization_id);
        self::assertTrue($actor->user->belongsToOrganization((int) $actor->organization->id));
        $project = Project::factory()->create(['organization_id' => $actor->organization->id, 'is_archived' => false]);
        $project->users()->attach($actor->user->id, ['role' => 'project_manager', 'is_active' => true]);
        self::assertTrue(app(UserProjectAccessService::class)->queryAccessibleProjects($actor->user, (int) $actor->organization->id)->whereKey($project->id)->exists());
        self::assertTrue(app(AssistantDataAccessPolicy::class)->accessibleProjects($actor->user, (int) $actor->organization->id)->whereKey($project->id)->exists());

        return $project;
    }
}
