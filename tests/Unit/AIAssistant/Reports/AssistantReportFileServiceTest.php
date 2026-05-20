<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportFileService;
use App\Models\Organization;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AssistantReportFileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_report_file_and_returns_normalized_artifact(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $path = 'org-'.$organization->id.'/reports/timeline.pdf';
        $fileService = Mockery::mock(FileService::class);
        $fileService
            ->shouldReceive('temporaryUrl')
            ->once()
            ->with($path, 1440, Mockery::on(static fn (Organization $value): bool => (int) $value->id === (int) $organization->id))
            ->andReturn('https://files.example.test/timeline.pdf');

        $service = new AssistantReportFileService($fileService);

        $artifacts = $service->artifactsFromToolResult(
            'generate_project_timelines_report',
            [
                'status' => 'success',
                'pdf_url' => 'https://storage.example.test/timeline.pdf',
                'filename' => 'timeline.pdf',
                'storage_disk' => 's3',
                'storage_path' => $path,
                'expires_at' => '2026-05-21T12:00:00+03:00',
            ],
            $organization,
            $user,
            [
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-20',
                'project_id' => 12,
            ]
        );

        $this->assertCount(1, $artifacts);
        $this->assertSame('pdf', $artifacts[0]['type']);
        $this->assertSame('https://files.example.test/timeline.pdf', $artifacts[0]['download_url']);
        $this->assertSame($path, $artifacts[0]['storage_path']);
        $this->assertSame('project_timelines', $artifacts[0]['report_type']);
        $this->assertSame(12, $artifacts[0]['filters']['project_id']);
        $this->assertDatabaseHas('report_files', [
            'path' => $path,
            'organization_id' => $organization->id,
            'filename' => 'timeline.pdf',
            'type' => 'reports',
            'user_id' => $user->id,
        ]);
    }

    public function test_rejects_artifact_without_organization_report_storage_evidence(): void
    {
        $organization = Organization::factory()->create();
        $fileService = Mockery::mock(FileService::class);
        $fileService->shouldNotReceive('temporaryUrl');

        $service = new AssistantReportFileService($fileService);

        $artifacts = $service->artifactsFromToolResult(
            'generate_project_timelines_report',
            [
                'status' => 'success',
                'pdf_url' => 'https://storage.example.test/timeline.pdf',
                'filename' => 'timeline.pdf',
                'storage_disk' => 's3',
                'storage_path' => 'org-999/reports/timeline.pdf',
            ],
            $organization,
            null,
            []
        );

        $this->assertSame([], $artifacts);
        $this->assertSame(0, ReportFile::query()->count());
    }
}
