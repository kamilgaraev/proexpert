<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompositionService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignWorkflowService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use DomainException;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\TestCase;

final class DesignNonEmptyReleaseTest extends TestCase
{
    public function test_optional_composition_can_be_approved_but_empty_release_is_blocked(): void
    {
        [$context, $package] = $this->fixture();
        $this->assertSame('approved', $package->composition_status);
        $check = app(DesignCompletenessService::class)->run($package, (int) $context->user->id);
        $this->assertSame('blocked', $check->status->value);
        $this->assertContains('current_source_file', array_column($check->results, 'rule'));
    }

    #[DataProvider('emptyWorkflowCases')]
    public function test_all_workflow_gates_reject_empty_scope_and_old_cached_ready(string $action, string $status, bool $excluded): void
    {
        [$context, $package] = $this->fixture($excluded);
        $package->update(['status' => $status]);
        $service = app(DesignCompletenessService::class);
        $check = $service->run($package->fresh(), (int) $context->user->id);
        $metadata = $check->metadata;
        $metadata['rule_count'] = 6;
        $check->update(['status' => 'ready', 'blocking_count' => 0, 'results' => [], 'metadata' => $metadata]);
        $this->assertFalse($service->isFreshForPackage($package->fresh(), $check->fresh()));
        $registry = app(DesignManagementService::class);
        $this->assertSame(0, $registry->listPackages((int) $package->organization_id, ['readiness_status' => 'ready'], $context->user)->total());
        $this->assertSame(1, $registry->listPackages((int) $package->organization_id, ['readiness_status' => 'not_checked'], $context->user)->total());
        try {
            app(DesignWorkflowService::class)->transition($package->fresh(), (int) $context->user->id, $action);
            $this->fail('An empty selected release cannot advance.');
        } catch (DomainException $exception) {
            $this->assertSame(trans_message('design_management.errors.completeness_blocked'), $exception->getMessage());
        }
        $this->assertSame($status, $package->fresh()->status->value);
        $this->assertSame(0, $package->workflowEvents()->count());
        $this->assertNull($package->fresh()->issued_at);
    }

    public static function emptyWorkflowCases(): array
    {
        $cases = [];
        foreach (['submit_norm_control' => 'draft', 'submit_customer_review' => 'under_norm_control', 'approve' => 'under_customer_review', 'issue' => 'approved'] as $action => $status) {
            foreach ([false, true] as $excluded) {
                $cases[$action.($excluded ? ' excluded files' : ' optional positions')] = [$action, $status, $excluded];
            }
        }
        return $cases;
    }

    public function test_active_optional_file_allows_release_but_blank_path_invalidates_freshness(): void
    {
        $this->freezeTime();
        [$context, $package, $artifact] = $this->fixture();
        $version = $this->version($artifact);
        $service = app(DesignCompletenessService::class);
        $check = $service->run($package->fresh(), (int) $context->user->id);
        $this->assertSame('ready', $check->status->value);
        $this->assertTrue($service->isFreshForPackage($package->fresh(), $check));
        $version->update(['source_file_path' => '   ']);
        $this->assertFalse($service->isFreshForPackage($package->fresh(), $check));
        $blocked = $service->run($package->fresh(), (int) $context->user->id);
        $this->assertSame('blocked', $blocked->status->value);
        $version->update(['source_file_path' => 'org-'.$package->organization_id.'/active.ifc']);
        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $package = app(DesignWorkflowService::class)->transition($package->fresh(), (int) $context->user->id, $action);
        }
        $this->assertSame('issued', $package->status->value);
        $event = $package->workflowEvents()->where('action', 'issue')->sole();
        $this->assertSame([$version->id], $event->metadata['artifact_version_ids']);
    }

    public function test_legacy_active_bound_file_counts_but_standalone_or_inactive_history_does_not(): void
    {
        [$context, $package, $artifact] = $this->fixture();
        $package->update(['composition_revision_id' => null, 'composition_status' => 'draft']);
        $this->version($artifact);
        $service = app(DesignCompletenessService::class);
        $this->assertSame('ready', $service->run($package->fresh(), (int) $context->user->id)->status->value);
        $artifact->section->update(['metadata' => ['composition_active' => false, 'documents' => []]]);
        $this->assertSame('blocked', $service->run($package->fresh(), (int) $context->user->id)->status->value);
    }

    private function fixture(bool $excluded = false): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        DesignManagementTestEntitlements::activate($context);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['is_active' => true, 'role' => 'project_manager']);
        UserRoleAssignment::assignRole($context->user, 'project_manager', AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id));
        Cache::store('array')->flush();
        $scope = ['organization_id' => $context->organization->id, 'project_id' => $project->id];
        foreach (['edit', 'review', 'approve', 'norm_control.run', 'composition.edit', 'composition.approve'] as $permission) {
            $this->assertTrue(app(AuthorizationService::class)->can($context->user, 'design-management.'.$permission, $scope));
        }
        $package = DesignPackage::query()->create($scope + ['title' => 'Необязательная BIM-документация', 'project_stage' => 'rd', 'status' => 'draft', 'created_by' => $context->user->id, 'updated_by' => $context->user->id]);
        $composition = app(DesignCompositionService::class);
        $revision = $composition->createRevision($package, $context->user, ['composition' => ['brand' => 'BIM', 'document_groups' => [[
            'code' => 'BIM', 'required' => false, 'documents' => [['document_code' => 'BIM-01', 'document_title' => 'Модель', 'required' => false, 'artifact_type' => 'model', 'allowed_formats' => ['ifc'], 'sheet_registry_required' => false]],
        ]]]]);
        $artifact = $package->artifacts()->where('document_code', 'BIM-01')->sole();
        if ($excluded) {
            $this->version($artifact);
            $composition->exclude($revision, $context->user, ['item_key' => 'BIM', 'reason' => 'Вне выпуска', 'expected_state_version' => $revision->state_version]);
        }
        $composition->approve($revision->fresh(), $context->user);
        $standalone = DesignArtifact::query()->create($scope + ['package_id' => $package->id, 'artifact_type' => 'model', 'title' => 'Отдельная модель']);
        $this->version($standalone);
        return [$context, $package->fresh(), $artifact];
    }

    private function version(DesignArtifact $artifact): DesignArtifactVersion
    {
        return $artifact->versions()->create([
            'organization_id' => $artifact->organization_id, 'project_id' => $artifact->project_id,
            'title' => $artifact->title, 'version_number' => '1', 'revision' => '1', 'source_format' => 'ifc', 'file_format' => 'ifc',
            'source_file_path' => 'org-'.$artifact->organization_id.'/model-'.$artifact->id.'.ifc', 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 100, 'is_current' => true, 'status' => 'current',
        ]);
    }
}
