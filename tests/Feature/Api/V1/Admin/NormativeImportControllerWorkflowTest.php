<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Jobs\ImportNormativeBaseJob;
use App\Models\NormativeBaseType;
use App\Models\NormativeImportLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class NormativeImportControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_queues_import_inside_current_organization_and_returns_admin_contract(): void
    {
        Queue::fake();
        Storage::fake('local');
        $context = AdminApiTestContext::create();
        $this->createBaseType('fer');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/estimates/import/upload', [
                'base_type_code' => 'fer',
                'file' => UploadedFile::fake()->create('fer.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ]);

        $response->assertAccepted();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.import_log.organization_id', $context->organization->id);
        $response->assertJsonPath('data.import_log.status', 'queued');

        $filePath = $response->json('data.import_log.file_path');
        $this->assertIsString($filePath);
        $this->assertStringStartsWith('normative-imports/', $filePath);
        Storage::disk('local')->assertExists($filePath);

        Queue::assertPushed(ImportNormativeBaseJob::class);
    }

    public function test_history_is_tenant_scoped_and_paginated_for_admin_ui(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = User::factory()->create(['current_organization_id' => $foreignOrganization->id]);
        $this->createBaseType('fer');

        $currentLog = $this->createImportLog($context->organization->id, $context->user->id, [
            'original_filename' => 'current.xlsx',
        ]);
        $foreignLog = $this->createImportLog($foreignOrganization->id, $foreignUser->id, [
            'original_filename' => 'foreign.xlsx',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimates/import/history?page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($currentLog->id, $ids);
        $this->assertNotContains($foreignLog->id, $ids);
    }

    public function test_status_and_retry_do_not_expose_foreign_import_logs(): void
    {
        Queue::fake();
        Storage::fake('local');
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = User::factory()->create(['current_organization_id' => $foreignOrganization->id]);
        $this->createBaseType('fer');

        $ownLog = $this->createImportLog($context->organization->id, $context->user->id, [
            'status' => 'failed',
            'file_path' => 'normative-imports/current.xlsx',
        ]);
        $foreignLog = $this->createImportLog($foreignOrganization->id, $foreignUser->id, [
            'status' => 'failed',
            'file_path' => 'normative-imports/foreign.xlsx',
        ]);
        Storage::disk('local')->put($ownLog->file_path, 'content');
        Storage::disk('local')->put($foreignLog->file_path, 'content');

        $foreignStatus = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimates/import/status/{$foreignLog->id}");
        $foreignRetry = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/import/retry/{$foreignLog->id}");

        $foreignStatus->assertNotFound();
        $foreignStatus->assertJsonPath('success', false);
        $foreignRetry->assertNotFound();
        $foreignRetry->assertJsonPath('success', false);

        $ownRetry = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/import/retry/{$ownLog->id}");

        $ownRetry->assertOk();
        $ownRetry->assertJsonPath('success', true);
        $ownRetry->assertJsonPath('data.import_log.id', $ownLog->id);
        $this->assertDatabaseHas('normative_import_logs', [
            'id' => $ownLog->id,
            'status' => 'queued',
            'error_message' => null,
        ]);
        Queue::assertPushed(ImportNormativeBaseJob::class);
    }

    public function test_retry_rejects_processing_and_missing_file_with_admin_errors(): void
    {
        Queue::fake();
        Storage::fake('local');
        $context = AdminApiTestContext::create();
        $this->createBaseType('fer');

        $processingLog = $this->createImportLog($context->organization->id, $context->user->id, [
            'status' => 'processing',
            'file_path' => 'normative-imports/processing.xlsx',
        ]);
        $missingFileLog = $this->createImportLog($context->organization->id, $context->user->id, [
            'status' => 'failed',
            'file_path' => 'normative-imports/missing.xlsx',
        ]);

        $processingResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/import/retry/{$processingLog->id}");
        $missingFileResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/import/retry/{$missingFileLog->id}");

        $processingResponse->assertBadRequest();
        $processingResponse->assertJsonPath('success', false);
        $missingFileResponse->assertNotFound();
        $missingFileResponse->assertJsonPath('success', false);
        Queue::assertNothingPushed();
    }

    private function createBaseType(string $code): NormativeBaseType
    {
        return NormativeBaseType::query()->create([
            'code' => $code,
            'name' => strtoupper($code),
            'is_active' => true,
        ]);
    }

    private function createImportLog(int $organizationId, int $userId, array $overrides = []): NormativeImportLog
    {
        return NormativeImportLog::query()->create(array_merge([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'base_type_code' => 'fer',
            'file_path' => 'normative-imports/import-'.$organizationId.'-'.$userId.'.xlsx',
            'original_filename' => 'import.xlsx',
            'status' => 'queued',
        ], $overrides));
    }
}
