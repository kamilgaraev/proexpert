<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignPulseFactSource;
use App\Models\Organization;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;
use Tests\TestCase;

final class DesignPulseFactSourceTest extends TestCase
{
    public function test_collects_design_management_risks(): void
    {
        $this->mockModuleAccess(true);
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
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

        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id));
        $types = $facts->pluck('type')->all();

        $this->assertContains('design_model_derivative_missing', $types);
        $this->assertContains('design_package_overdue', $types);
        $this->assertContains('design_model_preparation_failed', $types);
        $this->assertSame('design_management', $facts->first()->source);
    }

    public function test_ready_derivative_removes_missing_viewer_risk(): void
    {
        $this->mockModuleAccess(true);
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $package = $this->createPackage($organization->id, $project->id);
        $version = $this->createModelVersion($package);

        DesignModelDerivative::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => 'org-1/pir/model.frag',
            'status' => 'ready',
            'metadata' => [],
        ]);

        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id));

        $this->assertNotContains('design_model_derivative_missing', $facts->pluck('type')->all());
    }

    public function test_inactive_module_returns_no_design_facts(): void
    {
        $this->mockModuleAccess(false);
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $package = $this->createPackage($organization->id, $project->id);
        $this->createModelVersion($package);

        $facts = app(DesignPulseFactSource::class)->collect($this->context($organization->id, $project->id));

        $this->assertCount(0, $facts);
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
            'source_file_path' => 'org-1/pir/model.ifc',
            'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 1024,
            'status' => 'uploaded',
            'is_current' => true,
            'metadata' => [],
        ]);
    }

    private function context(int $organizationId, int $projectId): ProjectPulseContext
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
            userId: null,
        );
    }

    private function mockModuleAccess(bool $allowed): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock) use ($allowed): void {
            $mock->shouldReceive('hasModuleAccess')
                ->withAnyArgs()
                ->andReturn($allowed);
        });
    }
}
