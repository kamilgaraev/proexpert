<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\PersonalFile;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PersonalFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_only_current_organization_personal_files(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->create();
        $foreignFile = PersonalFile::query()->create([
            'organization_id' => $foreignOrganization->id,
            'user_id' => $context->user->id,
            'storage_key' => 'org-'.$foreignOrganization->id.'/personal-files/user-'.$context->user->id.'/foreign.pdf',
            'directory' => 'docs',
            'original_name' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('a', 64),
            'size' => 10,
            'is_folder' => false,
        ]);
        $storedKey = null;
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->andReturnUsing(function (string $key, mixed $contents, string $mime, string $sha256) use (&$storedKey): CurrentStoredFile {
                $storedKey = $key;

                return new CurrentStoredFile($key, 'etag', 12 * 1024, $sha256, $mime);
            });
        $files->shouldReceive('temporaryDownloadUrl')
            ->once()
            ->andReturn('https://download.example.test/personal');
        $files->shouldReceive('deleteCurrent')
            ->once()
            ->with(Mockery::on(static fn (string $key): bool => $key === $storedKey));
        $this->app->instance(FileService::class, $files);

        $createFolderResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/folder', ['name' => 'docs']);

        $createFolderResponse->assertCreated();
        $createFolderResponse->assertJsonPath('data.path', 'docs');
        $this->assertDatabaseHas('personal_files', [
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'directory' => '',
            'original_name' => 'docs',
            'is_folder' => true,
        ]);

        $uploadResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/personal-files/upload', [
                'parent_path' => 'docs',
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ]);

        $uploadResponse->assertCreated();
        $uploadResponse->assertJsonPath('data.filename', 'contract.pdf');
        $uploadResponse->assertJsonPath('data.path', 'docs/contract.pdf');
        $this->assertIsString($storedKey);
        $this->assertStringStartsWith(
            'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/',
            $storedKey,
        );
        $this->assertDatabaseHas('personal_files', [
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => $storedKey,
            'directory' => 'docs',
            'original_name' => 'contract.pdf',
            'is_folder' => false,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?folder=docs&per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.filename', 'contract.pdf');
        $this->assertNotContains(
            $foreignFile->id,
            collect($indexResponse->json('data'))->pluck('id')->all(),
        );

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/personal-files/'.$uploadResponse->json('data.id'));

        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('personal_files', ['id' => $uploadResponse->json('data.id')]);
    }

    public function test_personal_folder_names_reject_path_traversal(): void
    {
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/folder', ['name' => '../private']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseCount('personal_files', 0);
    }

    public function test_deleting_personal_folder_is_scoped_to_organization_and_user(): void
    {
        $context = AdminApiTestContext::create();
        $folder = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => null,
            'directory' => '',
            'original_name' => 'docs',
            'size' => 0,
            'is_folder' => true,
        ]);
        $nestedFile = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/nested.pdf',
            'directory' => 'docs',
            'original_name' => 'contract.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('b', 64),
            'size' => 12,
            'is_folder' => false,
        ]);
        $siblingFile = PersonalFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'storage_key' => 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/sibling.pdf',
            'directory' => 'archive',
            'original_name' => 'contract.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('c', 64),
            'size' => 12,
            'is_folder' => false,
        ]);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('deleteCurrent')->once()->with($nestedFile->storage_key);
        $this->app->instance(FileService::class, $files);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/personal-files/'.$folder->id);

        $response->assertOk();
        $this->assertDatabaseMissing('personal_files', ['id' => $folder->id]);
        $this->assertDatabaseMissing('personal_files', ['id' => $nestedFile->id]);
        $this->assertDatabaseHas('personal_files', ['id' => $siblingFile->id]);
    }

    public function test_personal_upload_failure_never_creates_registry_record(): void
    {
        $context = AdminApiTestContext::create();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')->once()->andThrow(new RuntimeException('storage failed'));
        $this->app->instance(FileService::class, $files);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/personal-files/upload', [
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ]);

        $response->assertStatus(500);
        $this->assertSame(0, PersonalFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->count());
    }
}
