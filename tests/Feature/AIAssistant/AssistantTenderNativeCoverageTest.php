<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Models\TenderFile;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Mockery;
use Tests\TestCase;

final class AssistantTenderNativeCoverageTest extends TestCase
{
    public function test_native_uuid_card_is_visible_in_current_scoped_coverage_without_inventing_storage_or_indexed_content(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug], ['project-management', 'tenders', 'ai-assistant'])));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $storage = Mockery::mock(FileService::class);
        $storage->shouldNotReceive('readCurrentBounded');
        $this->app->instance(FileService::class, $storage);
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]));
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $file = $this->nativeFile($organization, $project, 'Доступный файл');
        $this->nativeFile($organization, $hidden, 'Скрытый файл');
        $coverage = $this->app->make(AssistantDocumentCoverageService::class)->coverage($organization->id, $actor);
        $this->assertSame(1, $coverage['document_coverage']['total']);
        $this->assertSame(1, $coverage['document_coverage']['storage_unverified']);
        $this->assertSame(0, $coverage['document_coverage']['ready']);
        $this->assertSame(1, $coverage['archive_scan']['expected_file_count']);
        $this->assertSame(0, $coverage['native_attachment_coverage']['tender_file']['indexed_file_count']);
        $this->assertSame('metadata_only', $coverage['native_attachment_coverage']['tender_file']['content_scope']);
        $reader = new AssistantDomainReadService(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy, $authorization);
        $result = $reader->execute('read', ['domain' => 'tenders', 'entity_type' => 'tender_file', 'id' => $file->id,
            'fields' => ['id', 'original_name', 'mime_type', 'size']], $actor, $organization->id);
        $this->assertSame($file->id, $result['results'][0]['attachment_coverage']['source_ref']['entity_id']);
        $this->assertStringContainsString('Сохранена карточка файла; содержимое недоступно для чтения', $result['server_formatted_facts']);
        $this->assertStringNotContainsString($file->stored_path, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(0, File::query()->count());
        $this->assertSame(0, AIAssistantDocument::query()->count());
        $actor->assignedProjects()->detach($project->id);
        $revoked = $this->app->make(AssistantDocumentCoverageService::class)->coverage($organization->id, $actor);
        $this->assertSame(0, $revoked['document_coverage']['total']);
        $this->assertSame([], $revoked['native_attachment_coverage']);
    }

    private function nativeFile(Organization $organization, Project $project, string $title): TenderFile
    {
        $tender = Tender::withoutEvents(fn () => Tender::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'number' => 'T-'.$project->id, 'title' => $title]));

        return TenderFile::withoutEvents(fn () => TenderFile::query()->create(['tender_id' => $tender->id, 'category' => 'general',
            'original_name' => $title.'.pdf', 'stored_path' => 'unverified-native/'.$tender->id.'/document.pdf',
            'mime_type' => 'application/pdf', 'size' => 17, 'uploaded_at' => now()]));
    }
}
