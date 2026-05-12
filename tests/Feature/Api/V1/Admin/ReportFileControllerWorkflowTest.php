<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_files_are_filtered_renamed_and_deleted_inside_user_reports_folder(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $reportPath = $context->user->id . '/reports/cash-flow.xlsx';
        $personalPath = $context->user->id . '/docs/private.xlsx';
        Storage::disk('s3')->put($reportPath, 'report content');
        Storage::disk('s3')->put($personalPath, 'personal content');

        $reportFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $reportPath,
            'filename' => 'cash-flow.xlsx',
            'size' => 128,
            'is_folder' => false,
        ]);
        $personalFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $personalPath,
            'filename' => 'private.xlsx',
            'size' => 64,
            'is_folder' => false,
        ]);
        $foreignUser = User::factory()->create();
        $foreignReport = PersonalFile::query()->create([
            'user_id' => $foreignUser->id,
            'path' => $foreignUser->id . '/reports/foreign.xlsx',
            'filename' => 'foreign.xlsx',
            'size' => 32,
            'is_folder' => false,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?filename=cash&per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $reportFile->id);
        $this->assertStringContainsString('cash-flow.xlsx', (string) $indexResponse->json('data.0.download_url'));

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($personalFile->id, $ids);
        $this->assertNotContains($foreignReport->id, $ids);

        $renameResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/report-files/{$reportFile->id}", [
                'filename' => 'cash-flow-renamed.xlsx',
            ]);

        $renameResponse->assertOk();
        $renameResponse->assertJsonPath('success', true);
        $renameResponse->assertJsonPath('data.filename', 'cash-flow-renamed.xlsx');
        $this->assertDatabaseHas('personal_files', [
            'id' => $reportFile->id,
            'filename' => 'cash-flow-renamed.xlsx',
        ]);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/report-files/{$reportFile->id}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('personal_files', ['id' => $reportFile->id]);
        Storage::disk('s3')->assertMissing($reportPath);
        Storage::disk('s3')->assertExists($personalPath);
    }

    public function test_report_file_update_rejects_files_outside_reports_folder(): void
    {
        $context = AdminApiTestContext::create();
        $personalFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $context->user->id . '/docs/private.xlsx',
            'filename' => 'private.xlsx',
            'size' => 64,
            'is_folder' => false,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/report-files/{$personalFile->id}", [
                'filename' => 'should-not-rename.xlsx',
            ]);

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('personal_files', [
            'id' => $personalFile->id,
            'filename' => 'private.xlsx',
        ]);
    }
}
