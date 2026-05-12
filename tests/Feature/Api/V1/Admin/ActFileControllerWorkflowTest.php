<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Organization;
use App\Models\PersonalFile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ActFileControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_act_files_are_listed_downloaded_and_deleted_from_user_act_folder(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $path = $context->user->id . '/acts/act-scan.pdf';
        $foreignFolderPath = $context->user->id . '/reports/report.pdf';
        Storage::disk('s3')->put($path, 'personal act content');
        Storage::disk('s3')->put($foreignFolderPath, 'report content');

        $file = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $path,
            'filename' => 'act-scan.pdf',
            'size' => 20,
            'is_folder' => false,
        ]);
        $foreignFolderFile = PersonalFile::query()->create([
            'user_id' => $context->user->id,
            'path' => $foreignFolderPath,
            'filename' => 'report.pdf',
            'size' => 14,
            'is_folder' => false,
        ]);
        $foreignUser = User::factory()->create();
        $foreignUserFile = PersonalFile::query()->create([
            'user_id' => $foreignUser->id,
            'path' => $foreignUser->id . '/acts/foreign.pdf',
            'filename' => 'foreign.pdf',
            'size' => 10,
            'is_folder' => false,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/act-files?per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $file->id);

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
        Storage::disk('s3')->assertMissing($path);
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

    private function createActFixture(Organization $organization, User $user): array
    {
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Contractor',
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
