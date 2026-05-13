<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PersonalFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_personal_folder_file_registry_and_storage_objects(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $foreignFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/archive/foreign.pdf',
            'filename' => 'foreign.pdf',
            'size' => 10,
            'is_folder' => false,
        ]);

        $createFolderResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/folder', [
                'name' => 'docs',
            ]);

        $createFolderResponse->assertCreated();
        $createFolderResponse->assertJsonPath('success', true);
        $createFolderResponse->assertJsonPath('data.path', 'docs');
        $this->assertDatabaseHas('personal_files', [
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/docs/',
            'is_folder' => true,
        ]);

        $rootIndexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?per_page=10');

        $rootIndexResponse->assertOk();
        $rootItems = collect($rootIndexResponse->json('data'));
        $rootFolder = $rootItems->firstWhere('filename', 'docs');
        $this->assertNotNull($rootFolder);
        $this->assertTrue($rootFolder['is_folder']);
        $this->assertSame('docs', $rootFolder['path']);

        $uploadResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/personal-files/upload', [
                'parent_path' => 'docs',
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ]);

        $uploadResponse->assertCreated();
        $uploadResponse->assertJsonPath('success', true);
        $uploadResponse->assertJsonPath('data.filename', 'contract.pdf');
        $this->assertStringStartsWith('docs/', $uploadResponse->json('data.path'));
        $uploadedPath = $uploadResponse->json('data.path');
        $storedPath = $context->user->id . '/' . $uploadedPath;
        Storage::disk('s3')->assertExists($storedPath);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?folder=docs&per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.filename', 'contract.pdf');
        $this->assertStringStartsWith('docs/', $indexResponse->json('data.0.path'));

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignFile->id, $ids);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/personal-files/' . $uploadResponse->json('data.id'));

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('personal_files', ['id' => $uploadResponse->json('data.id')]);
        Storage::disk('s3')->assertMissing($storedPath);
    }

    public function test_personal_folder_names_reject_path_traversal(): void
    {
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/folder', [
                'name' => '../private',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseCount('personal_files', 0);
    }

    public function test_deleting_personal_folder_removes_nested_registry_records_and_storage_objects(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();

        $folder = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/docs/',
            'filename' => 'docs',
            'size' => 0,
            'is_folder' => true,
        ]);
        $nestedFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/docs/contract.pdf',
            'filename' => 'contract.pdf',
            'size' => 12,
            'is_folder' => false,
        ]);
        $siblingFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/archive/contract.pdf',
            'filename' => 'contract.pdf',
            'size' => 12,
            'is_folder' => false,
        ]);
        Storage::disk('s3')->put($nestedFile->path, 'pdf');
        Storage::disk('s3')->put($siblingFile->path, 'pdf');

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/personal-files/' . $folder->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('personal_files', ['id' => $folder->id]);
        $this->assertDatabaseMissing('personal_files', ['id' => $nestedFile->id]);
        $this->assertDatabaseHas('personal_files', ['id' => $siblingFile->id]);
        Storage::disk('s3')->assertMissing($nestedFile->path);
        Storage::disk('s3')->assertExists($siblingFile->path);
    }

    public function test_empty_file_filter_query_values_are_ignored(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();

        $personalResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?folder=&date_from=&date_to=&filename=&sort_by=created_at&sort_dir=desc&per_page=15&page=1');

        $personalResponse->assertOk();
        $personalResponse->assertJsonPath('success', true);
        $personalResponse->assertJsonPath('meta.total', 0);

        $actResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/act-files?filename=&date_from=&date_to=&sort_by=created_at&sort_dir=desc&per_page=15&page=1');

        $actResponse->assertOk();
        $actResponse->assertJsonPath('success', true);
        $actResponse->assertJsonPath('meta.total', 0);
    }

    public function test_personal_file_upload_does_not_create_record_when_storage_write_fails(): void
    {
        $context = AdminApiTestContext::create();

        $storage = Mockery::mock(Filesystem::class);
        $storage->shouldReceive('put')->once()->andReturn(false);

        $fileService = Mockery::mock(FileService::class);
        $fileService->shouldReceive('disk')->once()->andReturn($storage);

        $this->app->instance(FileService::class, $fileService);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/upload', [
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ]);

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        $this->assertSame(0, PersonalFile::query()->where('user_id', $context->user->id)->count());
    }
}
