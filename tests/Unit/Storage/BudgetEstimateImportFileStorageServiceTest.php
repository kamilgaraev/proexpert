<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BudgetEstimateImportFileStorageServiceTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function testStoresImportFilesInsideOrganizationPrefix(): void
    {
        Storage::fake('s3');

        $service = new FileStorageService();
        $tmpPath = tempnam(sys_get_temp_dir(), 'estimate-import-test-');
        $this->assertIsString($tmpPath);
        file_put_contents($tmpPath, 'test-content');

        $file = new UploadedFile(
            $tmpPath,
            'estimate.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $result = $service->store($file, 39);

        $this->assertStringStartsWith('org-39/estimate-imports/', $result['path']);
        $this->assertStringEndsWith('.xlsx', $result['path']);
        Storage::disk('s3')->assertExists($result['path']);
    }
}
