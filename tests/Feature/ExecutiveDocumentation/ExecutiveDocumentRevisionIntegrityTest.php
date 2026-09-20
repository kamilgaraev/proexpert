<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentRevisionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_actor_and_foreign_document_cannot_mutate_revision(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');

        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->addVersion($document, $foreign->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('foreign.pdf', 'foreign'),
        ]);
    }

    public function test_foreign_version_id_cannot_be_deleted(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        [$foreignContext, $foreignDocument, $foreignService] = $this->fixture();
        $version = $foreignService->addVersion($foreignDocument, $foreignContext->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('foreign.pdf', 'foreign'),
        ]);

        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->deleteVersion($document, $version, $context->user->id);
    }

    public function test_foreign_basis_links_are_rejected(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();

        $this->expectException(DomainException::class);
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('foreign-basis.pdf', 'foreign'),
            'basis_snapshot' => [
                'project_id' => $document->project_id,
                'completed_work_id' => 999001,
                'journal_entry_id' => 999002,
            ],
        ]);
    }

    public function test_profile_data_and_profile_snapshot_conflict_is_rejected(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();

        $this->expectException(DomainException::class);
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('profile.pdf', 'profile'),
            'profile_data' => ['drawing_set_code' => 'RD-1'],
            'profile_snapshot' => ['drawing_set_code' => 'RD-2'],
        ]);
    }

    public function test_failed_version_row_creation_removes_uploaded_file_and_keeps_card_unchanged(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $before = $document->fresh()->only(['status', 'profile_data']);
        $dispatcher = ExecutiveDocumentVersion::getEventDispatcher();
        Event::listen('eloquent.creating: '.ExecutiveDocumentVersion::class, static function (): void {
            throw new \RuntimeException('version_create_failed');
        });

        try {
            $service->addVersion($document, $context->user->id, [
                'version_number' => '1.0',
                'file' => UploadedFile::fake()->createWithContent('rollback.pdf', 'rollback'),
            ]);
            self::fail('Version creation should fail');
        } catch (\RuntimeException $exception) {
            self::assertSame('version_create_failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ExecutiveDocumentVersion::class);
            ExecutiveDocumentVersion::setEventDispatcher($dispatcher);
        }

        self::assertSame($before, $document->fresh()->only(['status', 'profile_data']));
        self::assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_empty_file_is_rejected_without_creating_version(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();

        $this->expectException(DomainException::class);
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('empty.pdf', ''),
        ]);
    }

    public function test_patch_updates_draft_profile_but_expected_version_cannot_edit_submitted_card(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('draft.pdf', 'draft'),
        ]);
        $updated = $service->updateDraft($document, $context->user->id, [
            'expected_version_id' => $version->id,
            'expected_revision' => 0,
            'profile_data' => ['drawing_set_code' => 'RD-DRAFT'],
        ]);

        self::assertSame('RD-DRAFT', $updated->profile_data['drawing_set_code']);
        self::assertSame('RD-DRAFT', $version->fresh()->profile_snapshot['drawing_set_code']);
        $service->submit($updated, $context->user->id, null, $version->id);

        $this->expectException(DomainException::class);
        $service->updateDraft($updated->fresh(), $context->user->id, [
            'expected_version_id' => $version->id,
            'expected_revision' => 1,
            'profile_data' => ['drawing_set_code' => 'RD-ILLEGAL'],
        ]);
    }

    public function test_deleting_last_draft_leaves_document_without_versions_and_not_ready(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('last.pdf', 'last'),
        ]);

        $service->deleteVersion($document, $version, $context->user->id);

        self::assertSame(0, $document->versions()->count());
        self::assertFalse($service->findDocument($document->id, $document->organization_id)->versions->isNotEmpty());
    }

    public function test_http_version_creation_requires_operation_key(): void
    {
        Storage::fake('s3');
        [$context, $document] = $this->fixture();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/documents/'.$document->id.'/versions', [
                'expected_version_id' => 0,
                'version_number' => '1.0',
                'file' => UploadedFile::fake()->createWithContent('missing-key.pdf', 'missing-key'),
            ])
            ;
        self::assertSame(422, $response->status(), $response->getContent());
        $response->assertJsonValidationErrors('operation_key');
    }

    public function test_http_patch_cannot_find_foreign_organization_document(): void
    {
        Storage::fake('s3');
        [$ownerContext, $document] = $this->fixture();
        $foreignContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->allowModuleAccess();

        $response = $this->withHeaders($foreignContext->authHeaders())
            ->patchJson('/api/v1/admin/executive-documentation/documents/'.$document->id, [
                'expected_version_id' => 1,
                'expected_revision' => 0,
                'profile_data' => ['drawing_set_code' => 'FOREIGN'],
            ])
            ;
        self::assertSame(404, $response->status(), $response->getContent());
    }

    private function allowModuleAccess(): void
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
    }

    private function fixture(): array
    {
        $this->allowModuleAccess();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'SET-'.uniqid(),
            'title' => 'Revision integrity set',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Revision integrity document',
            'status' => 'draft',
            'profile_data' => ['drawing_set_code' => 'RD-BASE'],
        ]);

        return [$context, $document, app(ExecutiveDocumentationService::class)];
    }
}
