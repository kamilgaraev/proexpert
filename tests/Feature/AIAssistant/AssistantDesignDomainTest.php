<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDesignFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeDocumentDiscovery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignManagementRagSource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AssistantDesignDomainTest extends TestCase
{
    private AssistantDataAccessPolicy $policy;
    private AssistantDomainReadService $reader;
    private Organization $organization;
    private User $actor;
    private Project $project;
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => ! in_array($permission, $this->denied, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'design-management'], (object) ['slug' => 'ai-assistant']]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        $this->reader = new AssistantDomainReadService(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->policy, $authorization);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
    }

    public function test_current_package_search_scopes_before_limit_and_returns_canonical_facts(): void
    {
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        for ($index = 0; $index < 12; $index++) { $this->package($hidden, 'ПИР скрытый '.$index); }
        $package = $this->package($this->project, 'ПИР доступный');
        $package->updateQuietly(['status' => 'in_work', 'planned_issue_date' => '2026-12-17']);
        $result = $this->reader->execute('search', ['domain' => 'design', 'entity_type' => 'design_package',
            'query' => 'ПИР', 'limit' => 1, 'fields' => ['id','title','status','planned_issue_date']], $this->actor, $this->organization->id);
        $this->assertSame($package->id, $result['results'][0]['id']);
        $this->assertSame('in_work', $result['structured_fact_evidence']['rows'][0]['fields']['status']);
        $this->assertSame($package->attributesToArray()['planned_issue_date'], $result['structured_fact_evidence']['rows'][0]['fields']['planned_issue_date']);
        $visibleFacts = strip_tags((string) (new GithubFlavoredMarkdownConverter)->convert($result['server_formatted_facts']));
        $this->assertStringContainsString('В работе', $visibleFacts);
        $this->assertStringContainsString('2026-12-17', $visibleFacts);
        $this->assertSame('/design-management/packages/'.$package->id, $result['source_refs'][0]['navigation']['url']);
        $this->assertTrue($this->policy->canReadReference($this->actor, $this->organization->id, $result['source_refs'][0]));
        $this->actor->assignedProjects()->detach($this->project->id);
        $this->assertFalse($this->policy->canReadReference($this->actor, $this->organization->id, $result['source_refs'][0]));
    }

    public function test_document_model_and_review_permissions_gate_rows_and_index_sources(): void
    {
        $package = $this->package($this->project, 'Комплект');
        $document = $this->artifact($package, 'drawing_set');
        $model = $this->artifact($package, 'model');
        $comment = DesignReviewComment::withoutEvents(fn () => DesignReviewComment::query()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'package_id' => $package->id, 'artifact_id' => $document->id, 'body' => 'Проверить размер', 'status' => 'open']));
        $set = DesignModelSet::withoutEvents(fn () => DesignModelSet::query()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'title' => 'Модели', 'revision' => 1]));
        $this->denied = ['design-management.models.view', 'design-management.review'];
        $this->assertTrue($this->policy->canReadEntity($this->actor, $this->organization->id, 'design_artifact', $document->id));
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'design_artifact', $model->id));
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'design_review_comment', $comment->id));
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'design_model_set', $set->id));
        $registry = $this->app->make(RagSourceRegistry::class);
        $this->assertInstanceOf(DesignManagementRagSource::class, $registry->collector('design'));
        $chunks = iterator_to_array((function () use ($registry) { yield from $registry->collector('design')->collectForOrganization($this->organization->id, $this->project->id); })());
        $this->assertCount(5, $chunks);
        $source = ['organization_id' => $this->organization->id, 'source_type' => 'design', 'entity_type' => 'design_artifact', 'entity_id' => $model->id, 'project_id' => $this->project->id];
        $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $source));
    }

    public function test_native_version_attachment_mapping_is_idempotent_current_and_inherits_acl(): void
    {
        Storage::fake('s3');
        $package = $this->package($this->project, 'Комплект');
        $artifact = $this->artifact($package, 'drawing_set');
        $bytes = "%PDF-1.4\nПИР тестовый документ\n%%EOF";
        $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'artifact_id' => $artifact->id,
            'title' => 'Чертёж', 'version_number' => '1', 'source_format' => 'pdf', 'file_format' => 'pdf',
            'source_file_path' => 'pending', 'source_original_name' => 'drawing.pdf', 'source_mime_type' => 'application/pdf',
            'source_size_bytes' => strlen($bytes), 'source_sha256' => hash('sha256', $bytes)]));
        $path = 'org-'.$this->organization->id.'/pir/projects/'.$this->project->id.'/packages/'.$package->id.'/documents/'.$version->id.'/source/drawing.pdf';
        $version->updateQuietly(['source_file_path' => $path]);
        Storage::disk('s3')->put($path, $bytes);
        $adapter = new AssistantDesignFileAdapter($this->policy);
        $file = $adapter->map($this->actor, $this->organization->id, $version->id, $path);
        $repeat = $adapter->map($this->actor, $this->organization->id, $version->id, $path);
        $this->assertSame($file->id, $repeat->id);
        $this->assertSame(1, File::query()->where('fileable_id', $version->id)->where('fileable_type', $version->getMorphClass())->count());
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->once()->with($path, 10, 25_000_001)
            ->andReturnUsing(static fn (string $path) => Storage::disk('s3')->readStream($path));
        $this->app->instance(FileService::class, $files);
        $document = $this->app->make(AssistantDocumentService::class)->register($this->actor, $this->organization->id,
            'design_artifact_version', $version->id, $path, 'ignored-client-name.pdf', 'application/pdf', $this->project->id);
        $this->assertSame($file->id, $document->file_id);
        $this->assertSame(strlen($bytes), $document->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $document->checksum);
        $this->assertTrue($this->policy->canReadEntity($this->actor, $this->organization->id, 'assistant_document', $document->id));
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $outsider = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $foreign->id, 'is_active' => true]));
        $outsider->organizations()->attach($foreign->id, ['is_active' => true]);
        $this->assertFalse($this->policy->canReadEntity($outsider, $foreign->id, 'assistant_document', $document->id));
        $this->denied = ['design-management.documents.view'];
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'file', $file->id));
        $this->denied = [];
        $version->updateQuietly(['source_sha256' => hash('sha256', 'changed')]);
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'assistant_document', $document->id));
        $this->assertFalse($this->policy->accessibleFiles($this->actor, $this->organization->id)->whereKey($file->id)->exists());
        $version->deleteQuietly();
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'file', $file->id));
        $this->expectException(RuntimeException::class);
        $adapter->assertMapping($file);
    }

    public function test_native_archive_discovery_rechecks_creator_project_access_before_limit_and_maps_only_authorized_sources(): void
    {
        Storage::fake('s3');
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $visible = null;
        foreach ([$hidden, $hidden, $hidden, $hidden, $hidden, $this->project] as $project) {
            $package = $this->package($project, 'Архив ПИР');
            $artifact = $this->artifact($package, 'drawing_set');
            $bytes = '%PDF-1.4 archive';
            $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
                'organization_id' => $this->organization->id, 'project_id' => $project->id, 'artifact_id' => $artifact->id,
                'title' => 'Архивный чертёж', 'version_number' => '1', 'source_format' => 'pdf', 'file_format' => 'pdf',
                'uploaded_by' => $this->actor->id, 'source_file_path' => 'pending', 'source_original_name' => 'archive.pdf', 'source_mime_type' => 'application/pdf',
                'source_size_bytes' => strlen($bytes), 'source_sha256' => hash('sha256', $bytes)]));
            $path = 'org-'.$this->organization->id.'/pir/projects/'.$project->id.'/packages/'.$package->id.'/documents/'.$version->id.'/source/archive.pdf';
            $version->updateQuietly(['source_file_path' => $path]);
            Storage::disk('s3')->put($path, $bytes);
            if ($project->id === $this->project->id) { $visible = $version; }
        }
        $discovery = $this->app->make(AssistantNativeDocumentDiscovery::class);
        $this->assertSame(1, $discovery->discover($this->organization->id, null));
        $file = File::query()->where('additional_info->assistant_native_source', 'design')->sole();
        $this->assertSame($visible->id, $file->fileable_id);
        $this->denied = ['design-management.documents.view'];
        $this->assertSame(0, $discovery->discover($this->organization->id, $this->actor->id));
        $this->assertFalse($this->policy->canReadEntity($this->actor, $this->organization->id, 'file', $file->id));
        $this->assertSame(1, File::query()->count());
    }

    private function package(Project $project, string $title): DesignPackage
    {
        return DesignPackage::withoutEvents(fn () => DesignPackage::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $project->id, 'title' => $title, 'status' => 'draft']));
    }

    private function artifact(DesignPackage $package, string $type): DesignArtifact
    {
        return DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $package->project_id, 'package_id' => $package->id, 'title' => 'Документ '.$type, 'artifact_type' => $type]));
    }
}
