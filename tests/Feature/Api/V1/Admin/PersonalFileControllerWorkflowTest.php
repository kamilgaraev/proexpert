<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $createFolderResponse->assertJsonPath('data.path', $context->user->id . '/docs/');
        $this->assertDatabaseHas('personal_files', [
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/docs/',
            'is_folder' => true,
        ]);

        $uploadResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/personal-files/upload', [
                'parent_path' => 'docs',
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ]);

        $uploadResponse->assertCreated();
        $uploadResponse->assertJsonPath('success', true);
        $uploadResponse->assertJsonPath('data.filename', 'contract.pdf');
        $uploadedPath = $uploadResponse->json('data.path');
        $this->assertStringStartsWith($context->user->id . '/docs/', $uploadedPath);
        Storage::disk('s3')->assertExists($uploadedPath);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/personal-files?folder=docs&per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.filename', 'contract.pdf');

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignFile->id, $ids);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/personal-files/' . $uploadResponse->json('data.id'));

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('personal_files', ['id' => $uploadResponse->json('data.id')]);
        Storage::disk('s3')->assertMissing($uploadedPath);
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
}
