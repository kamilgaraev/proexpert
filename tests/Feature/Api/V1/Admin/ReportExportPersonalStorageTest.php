<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Models\ReportFile;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportExportPersonalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_report_export_is_saved_to_report_storage_for_current_user(): void
    {
        $context = AdminApiTestContext::create();
        $this->actingAs($context->user, 'api_admin');
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->andReturnUsing(static fn (string $key, mixed $contents, string $mime, string $sha256): CurrentStoredFile => new CurrentStoredFile(
                $key,
                'etag',
                1024,
                $sha256,
                $mime,
            ));
        $this->app->instance(FileService::class, $files);

        $response = app(ExcelExporterService::class)->streamDownload(
            'cash_flow_report.xlsx',
            ['Наименование', 'Сумма'],
            [
                ['Аванс', 1200],
            ]
        );

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertNotFalse($content);
        $this->assertNotSame('', $content);

        $file = PersonalFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->where('storage_key', 'like', 'org-'.$context->organization->id.'/personal-files/user-'.$context->user->id.'/%')
            ->where('directory', 'reports')
            ->where('original_name', 'cash_flow_report.xlsx')
            ->first();

        $this->assertInstanceOf(PersonalFile::class, $file);
        $this->assertFalse($file->is_folder);
        $this->assertGreaterThan(0, $file->size);
        $this->assertSame(64, strlen((string) $file->sha256));

        $reportFile = ReportFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->where('path', $file->storage_key)
            ->where('filename', 'cash_flow_report.xlsx')
            ->first();

        $this->assertInstanceOf(ReportFile::class, $reportFile);
    }
}
