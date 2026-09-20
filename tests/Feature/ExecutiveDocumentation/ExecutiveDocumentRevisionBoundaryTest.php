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

final class ExecutiveDocumentRevisionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_actor_cannot_patch_or_delete_an_existing_draft(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'v1'),
        ]);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        foreach ([
            fn () => $service->updateDraft($document, $foreign->user->id, ['expected_revision' => 0, 'expected_version_id' => $version->id, 'title' => 'Wrong']),
            fn () => $service->deleteVersion($document, $version, $foreign->user->id),
        ] as $operation) {
            try {
                $operation();
                self::fail('Foreign mutation must fail');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(404, $exception->getCode());
            }
        }
        self::assertSame('Revision integrity document', $document->fresh()->title);
        self::assertNotNull($version->fresh());
    }

    public function test_assigned_project_boundary_is_enforced_for_organization_member(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $document->project->users()->detach($context->user->id);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'v1'),
        ]);
    }

    public function test_initial_upload_is_cleaned_when_the_outer_document_transaction_fails(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $set = $document->documentSet;
        $event = 'eloquent.creating: '.\App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRelation::class;
        Event::listen($event, static function (): void { throw new \RuntimeException('relation_creation_failed'); });
        try {
            $service->addDocument($set, $context->user->id, [
                'document_type' => 'working_drawing_set', 'title' => 'Failed card',
                'initial_version' => ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('initial.pdf', 'initial')],
                'relations' => [['relation_type' => 'test', 'target_type' => 'executive_document', 'target_id' => $document->id]],
            ]);
            self::fail('Outer transaction must fail');
        } catch (\RuntimeException $exception) {
            self::assertSame('relation_creation_failed', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        self::assertSame(1, $set->documents()->count());
        self::assertSame(0, ExecutiveDocumentVersion::query()->count());
        self::assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_legacy_hashless_revision_is_not_treated_as_an_editable_draft(): void
    {
        [$context, $document, $service] = $this->fixture();
        $version = $document->versions()->create([
            'organization_id' => $document->organization_id, 'uploaded_by' => $context->user->id,
            'version_number' => 'legacy', 'file_url' => 'org-legacy/original.pdf', 'status' => 'draft',
        ]);
        foreach ([
            fn () => $service->updateDraft($document, $context->user->id, ['expected_revision' => 0, 'expected_version_id' => $version->id, 'title' => 'Changed']),
            fn () => $service->deleteVersion($document, $version, $context->user->id),
        ] as $operation) {
            try { $operation(); self::fail('Legacy revision must be immutable'); }
            catch (DomainException) { self::assertNotNull($version->fresh()); }
        }
        self::assertSame('Revision integrity document', $document->fresh()->title);
    }

    public function test_retry_after_draft_edit_returns_original_operation_without_duplicate(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $payload = ['version_number' => '1', 'operation_key' => 'retry-edit', 'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'v1')];
        $version = $service->addVersion($document, $context->user->id, $payload);
        $service->updateDraft($document, $context->user->id, ['expected_revision' => 0, 'expected_version_id' => $version->id, 'profile_data' => ['drawing_set_code' => 'RD-NEW']]);
        $payload['file'] = UploadedFile::fake()->createWithContent('v1.pdf', 'v1');
        $retry = $service->addVersion($document->fresh(), $context->user->id, $payload);
        self::assertSame($version->id, $retry->id);
        self::assertSame(1, $document->versions()->count());
        self::assertSame('RD-NEW', $retry->profile_snapshot['drawing_set_code']);
    }

    public function test_two_edits_of_the_same_draft_require_the_current_revision_counter(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'v1'),
        ]);
        $service->updateDraft($document, $context->user->id, [
            'expected_version_id' => $version->id, 'expected_revision' => 0, 'title' => 'First editor',
        ]);
        try {
            $service->updateDraft($document->fresh(), $context->user->id, [
                'expected_version_id' => $version->id, 'expected_revision' => 0, 'title' => 'Stale editor',
            ]);
            self::fail('Stale draft must not overwrite the first edit');
        } catch (DomainException) {
            self::assertSame('First editor', $document->fresh()->title);
        }
    }

    public function test_http_version_and_draft_counter_contract(): void
    {
        Storage::fake('s3');
        [$context, $document] = $this->fixture();
        $response = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/executive-documentation/documents/'.$document->id.'/versions', [
            'expected_version_id' => 0, 'version_number' => '1', 'operation_key' => 'http-create-version',
            'file' => UploadedFile::fake()->createWithContent('v1.pdf', "%PDF-1.4\n".str_repeat(' ', 1100)),
        ]);
        $response->assertCreated()->assertJsonPath('data.revision', 0);
        $versionId = $response->json('data.id');
        $payload = ['expected_version_id' => $versionId, 'expected_revision' => 0, 'title' => 'HTTP title'];
        $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/executive-documentation/documents/'.$document->id, $payload)
            ->assertOk()->assertJsonPath('data.title', 'HTTP title')->assertJsonPath('data.versions.0.revision', 1);
        $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/executive-documentation/documents/'.$document->id, array_replace($payload, ['title' => 'Stale']))
            ->assertConflict();
        self::assertSame('HTTP title', $document->fresh()->title);
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
