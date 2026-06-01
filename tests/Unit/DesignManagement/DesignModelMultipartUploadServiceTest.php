<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelMultipartUploadService;
use App\BusinessModules\Features\DesignManagement\Services\DesignStoragePathService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class DesignModelMultipartUploadServiceTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function testUploadsApiChunkToS3MultipartUpload(): void
    {
        Cache::flush();

        $uploadId = 'upload-123';
        Cache::put('design_management:model_upload:' . $uploadId, [
            'upload_id' => $uploadId,
            's3_upload_id' => 's3-upload-123',
            'bucket' => 'prohelper-storage',
            'source_path' => 'org-7/pir/model-uploads/upload-123/building.ifc',
            'organization_id' => 7,
            'project_id' => 11,
            'package_id' => 21,
            'user_id' => 15,
            'part_size_bytes' => 5_242_880,
            'parts_count' => 2,
            'file' => [
                'original_name' => 'building.ifc',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 6_000_000,
            ],
            'uploaded_parts' => [],
            'payload' => [
                'title' => 'IFC',
                'version_number' => '1',
            ],
        ], now()->addDay());

        $s3Client = Mockery::mock(S3ClientInterface::class);
        $s3Client->shouldReceive('uploadPart')
            ->once()
            ->with(Mockery::on(static fn (array $payload): bool => $payload['Bucket'] === 'prohelper-storage'
                && $payload['Key'] === 'org-7/pir/model-uploads/upload-123/building.ifc'
                && $payload['UploadId'] === 's3-upload-123'
                && $payload['PartNumber'] === 1
                && $payload['ContentLength'] === 1024
                && is_resource($payload['Body'])))
            ->andReturn(new Result(['ETag' => '"etag-1"']));

        $tmpPath = tempnam(sys_get_temp_dir(), 'ifc-part-test-');
        $this->assertIsString($tmpPath);
        file_put_contents($tmpPath, str_repeat('A', 1024));

        $chunk = new UploadedFile(
            $tmpPath,
            'building.ifc.part-1',
            'application/octet-stream',
            null,
            true
        );
        $service = new DesignModelMultipartUploadService(
            $s3Client,
            new DesignStoragePathService(),
            $this->app->make(DesignManagementService::class)
        );

        $result = $service->uploadPart(7, 15, $uploadId, 1, $chunk);

        $this->assertSame($uploadId, $result['upload_id']);
        $this->assertSame(1, $result['part_number']);
        $this->assertSame('"etag-1"', $result['etag']);
        $this->assertSame(1024, $result['size_bytes']);
        $this->assertSame('"etag-1"', Cache::get('design_management:model_upload:' . $uploadId)['uploaded_parts'][1]['ETag']);
    }
}
