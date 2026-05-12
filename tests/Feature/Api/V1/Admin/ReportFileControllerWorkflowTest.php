<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_files_are_filtered_renamed_and_deleted_inside_organization_report_storage(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $reportPath = 'org-' . $context->organization->id . '/reports/cash-flow.xlsx';
        $personalPath = $context->user->id . '/docs/private.xlsx';
        Storage::disk('s3')->put($reportPath, 'report content');
        Storage::disk('s3')->put($personalPath, 'personal content');

        $reportFile = ReportFile::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'path' => $reportPath,
            'filename' => 'cash-flow.xlsx',
            'name' => 'cash-flow.xlsx',
            'type' => 'xlsx',
            'size' => 128,
            'expires_at' => now()->addYear(),
        ]);
        $foreignUser = User::factory()->create();
        $foreignReport = ReportFile::query()->create([
            'organization_id' => $context->organization->id + 1,
            'user_id' => $foreignUser->id,
            'path' => 'org-' . ($context->organization->id + 1) . '/reports/foreign.xlsx',
            'filename' => 'foreign.xlsx',
            'name' => 'foreign.xlsx',
            'type' => 'xlsx',
            'size' => 32,
            'expires_at' => now()->addYear(),
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?filename=cash&per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $reportFile->id);
        $this->assertStringContainsString('cash-flow.xlsx', (string) $indexResponse->json('data.0.download_url'));

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignReport->id, $ids);

        $renameResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/report-files/{$reportFile->id}", [
                'filename' => 'cash-flow-renamed.xlsx',
            ]);

        $renameResponse->assertOk();
        $renameResponse->assertJsonPath('success', true);
        $renameResponse->assertJsonPath('data.filename', 'cash-flow-renamed.xlsx');
        $this->assertDatabaseHas('report_files', [
            'id' => $reportFile->id,
            'filename' => 'cash-flow-renamed.xlsx',
            'name' => 'cash-flow-renamed.xlsx',
        ]);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/report-files/{$reportFile->id}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('report_files', ['id' => $reportFile->id]);
        Storage::disk('s3')->assertMissing($reportPath);
        Storage::disk('s3')->assertExists($personalPath);
    }

    public function test_report_file_update_rejects_files_from_another_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignReport = ReportFile::query()->create([
            'organization_id' => $context->organization->id + 1,
            'user_id' => $context->user->id,
            'path' => 'org-' . ($context->organization->id + 1) . '/reports/private.xlsx',
            'filename' => 'foreign.xlsx',
            'name' => 'foreign.xlsx',
            'type' => 'xlsx',
            'size' => 64,
            'expires_at' => now()->addYear(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/report-files/{$foreignReport->id}", [
                'filename' => 'should-not-rename.xlsx',
            ]);

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('report_files', [
            'id' => $foreignReport->id,
            'filename' => 'foreign.xlsx',
        ]);
    }

    public function test_legacy_user_report_files_without_organization_are_visible_only_to_owner(): void
    {
        $context = AdminApiTestContext::create();
        $foreignUser = User::factory()->create();

        $legacyReport = ReportFile::query()->create([
            'organization_id' => null,
            'user_id' => $context->user->id,
            'path' => 'reports/official-material-usage/2025/10/13/official.xlsx',
            'filename' => 'official.xlsx',
            'name' => 'official.xlsx',
            'type' => 'reports',
            'size' => 128,
            'expires_at' => now()->addYear(),
        ]);
        $foreignLegacyReport = ReportFile::query()->create([
            'organization_id' => null,
            'user_id' => $foreignUser->id,
            'path' => 'reports/official-material-usage/2025/10/13/foreign.xlsx',
            'filename' => 'foreign.xlsx',
            'name' => 'foreign.xlsx',
            'type' => 'reports',
            'size' => 64,
            'expires_at' => now()->addYear(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?type=reports&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($legacyReport->id, $ids);
        $this->assertNotContains($foreignLegacyReport->id, $ids);
    }
}
