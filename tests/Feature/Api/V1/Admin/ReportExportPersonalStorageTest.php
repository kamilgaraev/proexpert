<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Services\Export\ExcelExporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportExportPersonalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_report_export_is_saved_to_report_storage_for_current_user(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->actingAs($context->user, 'api_admin');

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
            ->where('user_id', $context->user->id)
            ->where('path', 'like', $context->user->id . '/reports/%')
            ->where('filename', 'cash_flow_report.xlsx')
            ->first();

        $this->assertInstanceOf(PersonalFile::class, $file);
        $this->assertFalse($file->is_folder);
        $this->assertGreaterThan(0, $file->size);
        Storage::disk('s3')->assertExists($file->path);
    }
}
