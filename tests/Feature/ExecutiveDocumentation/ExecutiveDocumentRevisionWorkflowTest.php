<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_creates_v2_and_keeps_presented_v1_snapshot_immutable(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->documentFixture();
        $profileBefore = $document->fresh()->profile_data;

        $v1 = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'original'),
            'profile_data' => $profileBefore,
            'basis_snapshot' => ['project_id' => $document->project_id],
            'operation_key' => 'revision-v1',
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $v1->id);
        $service->approve($document->fresh(), $context->user->id, null, $v1->id);

        $v2 = $service->addVersion($document->fresh(), $context->user->id, [
            'expected_version_id' => $v1->id,
            'version_number' => '2.0',
            'file' => UploadedFile::fake()->createWithContent('v2.pdf', 'corrected'),
            'profile_data' => array_replace($profileBefore, ['drawing_set_code' => 'RD-2']),
            'basis_snapshot' => ['project_id' => $document->project_id],
            'operation_key' => 'revision-v2',
        ]);

        self::assertNotSame($v1->id, $v2->id);
        self::assertSame('1.0', $v1->fresh()->version_number);
        self::assertSame('approved', $v1->fresh()->status);
        self::assertSame($profileBefore, $v1->fresh()->profile_snapshot);
        self::assertSame('2.0', $v2->version_number);
        self::assertSame(array_replace($profileBefore, ['drawing_set_code' => 'RD-2']), $v2->profile_snapshot);
        self::assertSame($v2->profile_snapshot, $document->fresh()->profile_data);
    }

    public function test_one_review_cycle_keeps_three_remarks_independent_and_blocks_approval_until_all_are_resolved(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('review.pdf', 'review'),
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $version->id);
        $remarks = collect(['one', 'two', 'three'])->map(fn (string $body) => $service->addRemark(
            $document->fresh(),
            $context->user->id,
            ['body' => $body, 'version_id' => $version->id],
        ));

        self::assertCount(3, $remarks->unique('id'));
        self::assertSame(3, $document->remarks()->where('version_id', $version->id)->where('status', 'open')->count());
        $this->expectException(\DomainException::class);
        $service->approve($document->fresh(), $context->user->id, null, $version->id);
    }

    public function test_operation_key_replay_returns_same_version_without_creating_duplicate(): void
    {
        Storage::fake('s3');
        [$context, $document, $service] = $this->documentFixture();
        $payload = [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('retry.pdf', 'same'),
            'operation_key' => 'same-operation',
        ];

        $first = $service->addVersion($document, $context->user->id, $payload);
        $payload['file'] = UploadedFile::fake()->createWithContent('retry.pdf', 'same');
        $second = $service->addVersion($document->fresh(), $context->user->id, $payload);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $document->versions()->where('operation_key', 'same-operation')->count());
    }

    public function test_same_operation_key_with_changed_file_or_snapshot_is_rejected(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('retry.pdf', 'first'),
            'profile_snapshot' => ['drawing_set_code' => 'RD-1'],
            'operation_key' => 'conflicting-operation',
        ]);

        $this->expectException(\DomainException::class);
        $service->addVersion($document->fresh(), $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('retry.pdf', 'second'),
            'profile_snapshot' => ['drawing_set_code' => 'RD-2'],
            'operation_key' => 'conflicting-operation',
        ]);
    }

    public function test_retry_of_version_creation_reuses_id_with_original_expected_version(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $v1 = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('base.pdf', 'base'),
        ]);
        $payload = [
            'expected_version_id' => $v1->id,
            'version_number' => '2.0',
            'file' => UploadedFile::fake()->createWithContent('retry-v2.pdf', 'v2'),
            'operation_key' => 'retry-v2-operation',
        ];
        $first = $service->addVersion($document->fresh(), $context->user->id, $payload);
        $payload['file'] = UploadedFile::fake()->createWithContent('retry-v2.pdf', 'v2');
        $retry = $service->addVersion($document->fresh(), $context->user->id, $payload);

        self::assertSame($first->id, $retry->id);
    }

    public function test_correction_rejects_stale_expected_version(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('stale.pdf', 'version'),
        ]);

        $this->expectException(\DomainException::class);
        $service->addVersion($document->fresh(), $context->user->id, [
            'expected_version_id' => $version->id + 1000,
            'version_number' => '2.0',
            'file' => UploadedFile::fake()->createWithContent('stale-2.pdf', 'version-two'),
        ]);
    }

    public function test_version_rejects_basis_snapshot_from_another_project(): void
    {
        [$context, $document, $service] = $this->documentFixture();

        $this->expectException(\DomainException::class);
        $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('foreign-basis.pdf', 'foreign'),
            'basis_snapshot' => ['project_id' => $document->project_id + 1000],
        ]);
    }

    public function test_transmitted_version_cannot_be_deleted_or_overwritten(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $version = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('transmitted.pdf', 'signed'),
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $version->id);
        $service->approve($document->fresh(), $context->user->id, null, $version->id);
        $set = ExecutiveDocumentSet::query()->findOrFail($document->document_set_id);
        \Tests\Support\ExecutiveDocumentRequirementFixture::cover($set, $version->fresh(), $context->user);
        $service->transmit($set, $context->user->id, ['transmittal_number' => 'TR-'.uniqid()]);

        $this->expectException(\DomainException::class);
        $service->deleteVersion($document->fresh(), $version->fresh(), $context->user->id);
    }

    public function test_old_approved_revision_cannot_be_resubmitted_after_a_correction(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $first = $service->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('one.pdf', 'one'),
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $first->id);
        $service->approve($document->fresh(), $context->user->id, null, $first->id);
        $second = $service->addVersion($document->fresh(), $context->user->id, [
            'version_number' => '2', 'file' => UploadedFile::fake()->createWithContent('two.pdf', 'two'),
            'expected_version_id' => $first->id,
        ]);

        try {
            $service->submit($document->fresh(), $context->user->id, null, $first->id);
            self::fail('Предъявление старой редакции должно быть отклонено');
        } catch (\DomainException) {
            self::assertSame('approved', $first->fresh()->status);
            self::assertSame('draft', $second->fresh()->status);
        }
    }

    private function documentFixture(): array
    {
        Storage::fake('s3');
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'SET-'.uniqid(),
            'title' => 'Revision test set',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Revision test document',
            'status' => 'draft',
            'profile_data' => [
                'drawing_set_code' => 'RD-1',
                'drawing_section' => 'АР',
                'sheet_list' => ['1'],
                'compliance_mark' => 'Соответствует',
                'responsible_person' => 'Инженер',
                'authority_document' => 'Доверенность',
                'drawing_set_status' => 'review',
            ],
        ]);

        return [$context, $document, app(ExecutiveDocumentationService::class)];
    }
}
