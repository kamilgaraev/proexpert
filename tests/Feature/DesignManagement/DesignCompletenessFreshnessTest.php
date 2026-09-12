<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Project;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignCompletenessFreshnessTest extends TestCase
{
    public function test_unchanged_inputs_are_fresh_and_document_state_change_makes_check_stale(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Проверка', 'project_stage' => 'pd', 'status' => 'draft', 'metadata' => []]);
        $artifact = DesignArtifact::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'artifact_type' => 'text_document', 'document_code' => 'AR-01', 'title' => 'АР', 'status' => 'active', 'metadata' => ['source' => 'test']]);
        $service = app(DesignCompletenessService::class);
        $check = $service->run($package, $context->user->id);

        $this->assertTrue($service->isFreshForPackage($package->fresh(), $check));
        $artifact->update(['metadata' => ['source' => 'test', 'changed' => true]]);
        $this->assertFalse($service->isFreshForPackage($package->fresh(), $check));
    }

    public function test_canonical_qc_status_and_blocking_flag_change_make_check_stale_without_timestamp_change(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'QC', 'project_stage' => 'pd', 'status' => 'draft', 'metadata' => []]);
        $issue = QualityDefect::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'kind' => 'project', 'created_by' => $context->user->id, 'defect_number' => 'PIR-FRESHNESS-1', 'title' => 'Блокер', 'description' => 'Проверить', 'status' => 'ready_for_review', 'metadata' => ['blocking' => ['active' => true], 'design_issue_context' => ['package_id' => $package->id]]]);
        $service = app(DesignCompletenessService::class);
        $check = $service->run($package, $context->user->id);
        $this->assertTrue($service->isFreshForPackage($package->fresh(), $check));
        $issue->timestamps = false;
        $issue->update(['status' => 'resolved', 'metadata' => ['blocking' => ['active' => false], 'design_issue_context' => ['package_id' => $package->id]]]);
        $this->assertFalse($service->isFreshForPackage($package->fresh(), $check));
    }
}
