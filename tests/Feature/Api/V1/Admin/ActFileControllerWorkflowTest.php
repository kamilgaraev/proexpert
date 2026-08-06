<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ContractorType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Organization;
use App\Models\PersonalFile;
use App\Models\Project;
use App\Models\User;
use App\Services\ActReport\ActReportNotificationService;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ActFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_act_files_are_listed_downloaded_and_deleted_from_user_act_folder(): void
    {
        $context = AdminApiTestContext::create();
        $path = 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/act-scan.pdf';
        $foreignFolderPath = 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/report.pdf';

        $file = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => $path,
            'directory' => 'acts',
            'original_name' => 'act-scan.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('a', 64),
            'size' => 20,
            'is_folder' => false,
        ]);
        $foreignFolderFile = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => $foreignFolderPath,
            'directory' => 'reports',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('b', 64),
            'size' => 14,
            'is_folder' => false,
        ]);
        $foreignUser = User::factory()->create();
        $foreignUserFile = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $foreignUser->id,
            'storage_key' => 'org-'.$context->organization->id.'/personal-files/user-'.$foreignUser->id.'/foreign.pdf',
            'directory' => 'acts',
            'original_name' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('c', 64),
            'size' => 10,
            'is_folder' => false,
        ]);
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'personal act content');
        rewind($stream);
        $fileService = Mockery::mock(FileService::class);
        $fileService->shouldReceive('temporaryDownloadUrl')->once()->with($path, 300)
            ->andReturn('https://download.example.test/act');
        $fileService->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $fileService->shouldReceive('deleteCurrent')->once()->with($path);
        $this->app->instance(FileService::class, $fileService);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/act-files?per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $file->id);
        $indexResponse->assertJsonPath('data.0.path', 'acts/act-scan.pdf');

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignFolderFile->id, $ids);
        $this->assertNotContains($foreignUserFile->id, $ids);

        $downloadResponse = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/act-files/{$file->id}");

        $downloadResponse->assertOk();
        $downloadResponse->assertDownload('act-scan.pdf');
        $this->assertSame('personal act content', $downloadResponse->streamedContent());

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/act-files/{$file->id}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('personal_files', ['id' => $file->id]);
    }

    public function test_act_report_files_are_listed_with_uploader_and_downloaded_as_binary_content(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        [$organization, $user, $act] = $this->createActFixture($context->organization, $context->user);
        $path = "org-{$organization->id}/acts/{$act->id}/documents/act-scan.pdf";
        Storage::disk('s3')->put($path, 'act binary content');
        $file = File::query()->create([
            'organization_id' => $organization->id,
            'fileable_id' => $act->id,
            'fileable_type' => ContractPerformanceAct::class,
            'user_id' => $user->id,
            'name' => 'act-scan.pdf',
            'original_name' => 'act-scan.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 18,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'act_document',
            'additional_info' => [
                'description' => 'Signed scan',
            ],
        ]);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/act-reports/{$act->id}/files");

        $listResponse->assertOk();
        $listResponse->assertJsonPath('success', true);
        $listResponse->assertJsonPath('data.0.id', $file->id);
        $listResponse->assertJsonPath('data.0.uploaded_by', $user->name);
        $listResponse->assertJsonPath('data.0.description', 'Signed scan');

        $downloadResponse = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/act-reports/{$act->id}/files/{$file->id}");

        $downloadResponse->assertOk();
        $downloadResponse->assertDownload('act-scan.pdf');
        $this->assertSame('act binary content', $downloadResponse->streamedContent());
    }

    public function test_act_report_file_cannot_be_downloaded_through_another_act(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        [$organization, $user, $firstAct] = $this->createActFixture($context->organization, $context->user);
        [, , $secondAct] = $this->createActFixture($context->organization, $context->user);

        $path = "org-{$organization->id}/acts/{$secondAct->id}/documents/foreign-act-scan.pdf";
        Storage::disk('s3')->put($path, 'foreign act content');
        $foreignFile = File::query()->create([
            'organization_id' => $organization->id,
            'fileable_id' => $secondAct->id,
            'fileable_type' => ContractPerformanceAct::class,
            'user_id' => $user->id,
            'name' => 'foreign-act-scan.pdf',
            'original_name' => 'foreign-act-scan.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 19,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'act_document',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/act-reports/{$firstAct->id}/files/{$foreignFile->id}");

        $response->assertNotFound();
    }

    public function test_act_report_file_upload_requires_edit_permission(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        [, , $act] = $this->createActFixture($context->organization, $context->user);

        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')
                ->andReturnUsing(static fn (User $user, string $permission): bool => $permission !== 'act_reports.edit');
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/act-reports/{$act->id}/files", [
                'file' => UploadedFile::fake()->create('act-scan.pdf', 10, 'application/pdf'),
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('files', 0);
    }

    public function test_second_signed_file_is_rejected_and_uploaded_object_is_removed(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        [$organization, , $act] = $this->createActFixture($context->organization, $context->user);
        $act->forceFill([
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'approval_date' => '2026-06-10',
        ])->save();
        $this->mock(ActReportNotificationService::class)->shouldIgnoreMissing();

        $first = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/act-reports/{$act->id}/signed-file", [
                'file' => UploadedFile::fake()->create('signed-first.pdf', 10, 'application/pdf'),
            ]);
        $first->assertOk();
        $first->assertJsonPath('success', true);
        $act->refresh();
        self::assertNotNull($act->signed_file_id);

        $second = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/act-reports/{$act->id}/signed-file", [
                'file' => UploadedFile::fake()->create('signed-second.pdf', 10, 'application/pdf'),
            ]);
        $second->assertStatus(409);
        $second->assertJsonPath('success', false);

        self::assertSame(1, File::query()
            ->where('organization_id', $organization->id)
            ->where('fileable_id', $act->id)
            ->where('fileable_type', ContractPerformanceAct::class)
            ->where('category', 'signed_act')
            ->count());
        self::assertCount(1, Storage::disk('s3')->allFiles());

        $signedFile = File::query()->findOrFail($act->signed_file_id);
        $delete = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/act-reports/{$act->id}/files/{$signedFile->id}");
        $delete->assertStatus(409);
        $delete->assertJsonPath('success', false);
        self::assertTrue(File::query()->whereKey($signedFile->id)->exists());
        Storage::disk('s3')->assertExists($signedFile->path);
    }

    public function test_contractor_organization_can_list_files_for_owner_contract_act(): void
    {
        $contractorContext = AdminApiTestContext::create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $ownerUser = User::factory()->create(['current_organization_id' => $ownerOrganization->id]);
        [$organization, $user, $act] = $this->createActFixture(
            $ownerOrganization,
            $ownerUser,
            $contractorContext->organization
        );
        $file = File::query()->create([
            'organization_id' => $organization->id,
            'fileable_id' => $act->id,
            'fileable_type' => ContractPerformanceAct::class,
            'user_id' => $user->id,
            'name' => 'act-scan.pdf',
            'original_name' => 'act-scan.pdf',
            'path' => "org-{$organization->id}/acts/{$act->id}/documents/act-scan.pdf",
            'mime_type' => 'application/pdf',
            'size' => 18,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'act_document',
        ]);

        $response = $this->withHeaders($contractorContext->authHeaders())
            ->getJson("/api/v1/admin/act-reports/{$act->id}/files");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $file->id);
    }

    public function test_act_file_copy_to_personal_storage_does_not_create_record_when_storage_copy_fails(): void
    {
        $context = AdminApiTestContext::create();
        [$organization, $user, $act] = $this->createActFixture($context->organization, $context->user);
        $path = "org-{$organization->id}/acts/{$act->id}/documents/act-scan.pdf";
        $file = File::query()->create([
            'organization_id' => $organization->id,
            'fileable_id' => $act->id,
            'fileable_type' => ContractPerformanceAct::class,
            'user_id' => $user->id,
            'name' => 'act-scan.pdf',
            'original_name' => 'act-scan.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 18,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'act_document',
        ]);

        $fileService = Mockery::mock(FileService::class);
        $fileService->shouldReceive('readCurrent')->once()->with($path)
            ->andThrow(new \RuntimeException('storage_object_read_failed'));

        $this->app->instance(FileService::class, $fileService);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/act-reports/{$act->id}/files/{$file->id}/copy-to-personal");

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        $this->assertSame(0, PersonalFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->count());
    }

    private function createActFixture(
        Organization $organization,
        User $user,
        ?Organization $contractorOrganization = null
    ): array {
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $contractorOrganization?->id,
            'name' => 'Contractor',
            'contractor_type' => $contractorOrganization
                ? ContractorType::INVITED_ORGANIZATION->value
                : ContractorType::MANUAL->value,
            'connected_at' => $contractorOrganization ? now() : null,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-FILE-1',
            'date' => '2026-06-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-FILE',
            'act_date' => '2026-06-10',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'amount' => 1000,
            'status' => ContractPerformanceAct::STATUS_DRAFT,
            'is_approved' => false,
            'created_by_user_id' => $user->id,
        ]);

        return [$organization, $user, $act];
    }
}
