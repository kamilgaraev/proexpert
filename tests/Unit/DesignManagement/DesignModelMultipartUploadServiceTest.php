<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelRegistrationService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelMultipartUploadService;
use App\BusinessModules\Features\DesignManagement\Services\DesignStoragePathService;
use App\Services\Storage\DTO\CurrentMultipartCompletion;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class DesignModelMultipartUploadServiceTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_uploads_api_chunk_to_s3_multipart_upload(): void
    {
        Cache::flush();

        $uploadId = 'upload-123';
        Cache::put('design_management:model_upload:'.$uploadId, [
            'upload_id' => $uploadId,
            's3_upload_id' => 's3-upload-123',
            'source_path' => 'org-7/pir/model-uploads/user-15/upload-123/building.ifc',
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

        $contents = str_repeat('A', 1024);
        $checksum = hash('sha256', $contents);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('uploadPart')
            ->once()
            ->with(
                Mockery::on(static fn (MultipartUpload $upload): bool => $upload->organizationPath
                    === 'org-7/pir/model-uploads/user-15/upload-123/building.ifc'
                    && $upload->uploadId === 's3-upload-123'
                    && $upload->partSizeBytes === 5_242_880),
                1,
                $contents,
                $checksum,
            )
            ->andReturnUsing(static function () use ($uploadId, $checksum): MultipartPart {
                $session = Cache::get('design_management:model_upload:'.$uploadId);
                $session['uploaded_parts'][2] = [
                    'PartNumber' => 2,
                    'ETag' => '"etag-2"',
                    'Size' => 1024,
                    'ChecksumSHA256' => str_repeat('b', 64),
                ];
                Cache::put('design_management:model_upload:'.$uploadId, $session, now()->addDay());

                return new MultipartPart(
                    'org-7/pir/model-uploads/user-15/upload-123/building.ifc',
                    's3-upload-123',
                    1,
                    '"etag-1"',
                    1024,
                    $checksum,
                );
            });

        $tmpPath = tempnam(sys_get_temp_dir(), 'ifc-part-test-');
        $this->assertIsString($tmpPath);
        file_put_contents($tmpPath, $contents);

        $chunk = new UploadedFile(
            $tmpPath,
            'building.ifc.part-1',
            'application/octet-stream',
            null,
            true
        );
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        $result = $service->uploadPart(7, 15, $uploadId, 1, $chunk);

        $this->assertSame($uploadId, $result['upload_id']);
        $this->assertSame(1, $result['part_number']);
        $this->assertSame('"etag-1"', $result['etag']);
        $this->assertSame(1024, $result['size_bytes']);
        $this->assertSame('"etag-1"', Cache::get('design_management:model_upload:'.$uploadId)['uploaded_parts'][1]['ETag']);
        $this->assertSame($checksum, Cache::get('design_management:model_upload:'.$uploadId)['uploaded_parts'][1]['ChecksumSHA256']);
        $this->assertSame('"etag-2"', Cache::get('design_management:model_upload:'.$uploadId)['uploaded_parts'][2]['ETag']);
    }

    public function test_start_uses_org_user_path_and_unified_storage_session(): void
    {
        Cache::flush();
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->once()->with($package);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('startMultipart')
            ->once()
            ->withArgs(static fn (string $path, string $mime, int $partSize, array $metadata): bool => str_starts_with(
                $path,
                'org-7/pir/projects/11/packages/21/model-uploads/user-15/',
            )
                && str_ends_with($path, '/source/building.ifc')
                && $mime === 'application/x-step'
                && $partSize === 5_242_880
                && ($metadata['user_id'] ?? null) === '15')
            ->andReturnUsing(static fn (
                string $path,
                string $mime,
                int $partSize,
                array $metadata,
            ): MultipartUpload => new MultipartUpload($path, 'provider-upload', $mime, $partSize, $metadata));

        $result = (new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        ))->start($package, 15, [
            'file_size_bytes' => 1024,
            'original_name' => 'building.ifc',
            'content_type' => 'application/x-step',
            'title' => 'IFC',
            'version_number' => '1',
        ]);

        $session = Cache::get('design_management:model_upload:'.$result['upload_id']);
        $this->assertIsArray($session);
        $this->assertStringContainsString('/user-15/', $session['source_path']);
        $this->assertArrayNotHasKey('bucket', $session);
        $this->assertSame('provider-upload', $session['s3_upload_id']);
    }

    public function test_start_aborts_provider_upload_when_cache_rejects_session(): void
    {
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->once()->with($package);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('startMultipart')
            ->once()
            ->andReturnUsing(static fn (
                string $path,
                string $mime,
                int $partSize,
                array $metadata,
            ): MultipartUpload => new MultipartUpload($path, 'provider-upload', $mime, $partSize, $metadata));
        $files->shouldReceive('abortMultipart')
            ->once()
            ->with(Mockery::on(static fn (MultipartUpload $upload): bool => $upload->uploadId === 'provider-upload'));
        Cache::shouldReceive('put')->once()->andReturn(false);
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        $this->expectException(\DomainException::class);
        $service->start($package, 15, [
            'file_size_bytes' => 1024,
            'original_name' => 'building.ifc',
            'content_type' => 'application/x-step',
            'title' => 'IFC',
            'version_number' => '1',
        ]);
    }

    public function test_start_aborts_provider_upload_when_cache_throws(): void
    {
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->once()->with($package);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('startMultipart')
            ->once()
            ->andReturnUsing(static fn (
                string $path,
                string $mime,
                int $partSize,
                array $metadata,
            ): MultipartUpload => new MultipartUpload($path, 'provider-upload', $mime, $partSize, $metadata));
        $files->shouldReceive('abortMultipart')->once();
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('cache_unavailable'));
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        $this->expectException(\DomainException::class);
        $service->start($package, 15, [
            'file_size_bytes' => 1024,
            'original_name' => 'building.ifc',
            'content_type' => 'application/x-step',
            'title' => 'IFC',
            'version_number' => '1',
        ]);
    }

    public function test_upload_part_fails_when_atomic_receipt_cannot_be_persisted(): void
    {
        $uploadId = 'upload-cache-failure';
        $path = 'org-7/pir/model-uploads/user-15/upload-cache-failure/building.ifc';
        $session = $this->multipartSession($uploadId, $path);
        $contents = str_repeat('A', 1024);
        $checksum = hash('sha256', $contents);
        $part = new MultipartPart($path, 'provider-'.$uploadId, 1, 'part-etag', 1024, $checksum);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('uploadPart')->once()->andReturn($part);
        Cache::shouldReceive('get')->twice()->with('design_management:model_upload:'.$uploadId)->andReturn($session);
        Cache::shouldReceive('lock')->once()->andReturn(new class
        {
            public function block(int $seconds, callable $callback): mixed
            {
                return $callback();
            }
        });
        Cache::shouldReceive('put')->once()->andReturn(false);
        $tmpPath = tempnam(sys_get_temp_dir(), 'ifc-part-cache-test-');
        $this->assertIsString($tmpPath);
        file_put_contents($tmpPath, $contents);
        $chunk = new UploadedFile(
            $tmpPath,
            'building.ifc.part-1',
            'application/octet-stream',
            null,
            true,
        );
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            Mockery::mock(DesignModelRegistrationService::class),
        );

        $this->expectException(\DomainException::class);
        $service->uploadPart(7, 15, $uploadId, 1, $chunk);
    }

    public function test_complete_retries_only_verification_after_transient_read_failure(): void
    {
        Cache::flush();
        $uploadId = 'upload-retry';
        $path = 'org-7/pir/model-uploads/user-15/upload-retry/building.ifc';
        Cache::put('design_management:model_upload:'.$uploadId, [
            'upload_id' => $uploadId,
            's3_upload_id' => 'provider-upload-retry',
            'source_path' => $path,
            'organization_id' => 7,
            'project_id' => 11,
            'package_id' => 21,
            'user_id' => 15,
            'part_size_bytes' => 5_242_880,
            'parts_count' => 1,
            'file' => [
                'original_name' => 'building.ifc',
                'mime_type' => 'application/x-step',
                'size_bytes' => 1024,
            ],
            'uploaded_parts' => [
                1 => [
                    'PartNumber' => 1,
                    'ETag' => 'part-etag',
                    'Size' => 1024,
                    'ChecksumSHA256' => str_repeat('a', 64),
                ],
            ],
            'completion' => null,
            'expires_at' => now()->addHours(2)->toISOString(),
            'payload' => ['title' => 'IFC', 'version_number' => '1'],
        ], now()->addHours(2));
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $version = new DesignArtifactVersion;
        $version->forceFill(['id' => 31]);
        $completion = new CurrentMultipartCompletion($path, 'object-etag', 1024, 'application/x-step');
        $stored = new CurrentStoredFile(
            $path,
            'object-etag',
            1024,
            hash('sha256', 'body'),
            'application/x-step',
        );
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('completeCurrentMultipart')->once()->andReturn($completion);
        $verificationAttempt = 0;
        $files->shouldReceive('verifyCurrentMultipart')
            ->twice()
            ->andReturnUsing(static function () use (&$verificationAttempt, $stored): CurrentStoredFile {
                $verificationAttempt++;
                if ($verificationAttempt === 1) {
                    throw new \RuntimeException('storage_object_read_failed');
                }

                return $stored;
            });
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('findPackage')->twice()->with(7, 21)->andReturn($package);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->twice()->with($package);
        $registrar->shouldReceive('registerStoredIfcModel')
            ->once()
            ->withArgs(static fn (
                DesignPackage $actualPackage,
                int $userId,
                string $sourcePath,
                array $fileInfo,
                array $payload,
            ): bool => $actualPackage === $package
                && $userId === 15
                && $sourcePath === $path
                && ($fileInfo['sha256'] ?? null) === $stored->sha256)
            ->andReturn($version);
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        try {
            $service->complete(7, 15, $uploadId);
            $this->fail('Transient verification failure was accepted.');
        } catch (\DomainException) {
        }

        $cached = Cache::get('design_management:model_upload:'.$uploadId);
        $this->assertSame('object-etag', $cached['completion']['etag']);
        $this->assertSame($version, $service->complete(7, 15, $uploadId));
        $this->assertNull(Cache::get('design_management:model_upload:'.$uploadId));
    }

    public function test_abort_uses_file_service_and_removes_owned_session(): void
    {
        Cache::flush();
        $uploadId = 'upload-abort';
        $path = 'org-7/pir/model-uploads/user-15/upload-abort/building.ifc';
        Cache::put(
            'design_management:model_upload:'.$uploadId,
            $this->multipartSession($uploadId, $path),
            now()->addHours(2),
        );
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('abortMultipart')
            ->once()
            ->with(Mockery::on(static fn (MultipartUpload $upload): bool => $upload->organizationPath === $path
                && $upload->uploadId === 'provider-'.$uploadId));
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturn(false);
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            Mockery::mock(DesignModelRegistrationService::class),
        );

        $service->abort(7, 15, $uploadId);

        $this->assertNull(Cache::get('design_management:model_upload:'.$uploadId));
    }

    public function test_abort_deletes_completed_object_after_frontend_handles_complete_error(): void
    {
        Cache::flush();
        $uploadId = 'upload-completed-abort';
        $path = 'org-7/pir/model-uploads/user-15/upload-completed-abort/building.ifc';
        $session = $this->multipartSession($uploadId, $path);
        $session['completion'] = [
            'key' => $path,
            'etag' => 'object-etag',
            'size_bytes' => 1024,
            'mime' => 'application/x-step',
        ];
        Cache::put('design_management:model_upload:'.$uploadId, $session, now()->addHours(2));
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('abortMultipart');
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturn(true);
        $files->shouldReceive('deleteCurrent')->once()->with($path);
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            Mockery::mock(DesignModelRegistrationService::class),
        );

        $service->abort(7, 15, $uploadId);

        $this->assertNull(Cache::get('design_management:model_upload:'.$uploadId));
    }

    public function test_abort_rejects_foreign_organization_or_user_without_storage_access(): void
    {
        Cache::flush();
        $uploadId = 'upload-foreign-abort';
        $path = 'org-7/pir/model-uploads/user-15/upload-foreign-abort/building.ifc';
        Cache::put(
            'design_management:model_upload:'.$uploadId,
            $this->multipartSession($uploadId, $path),
            now()->addHours(2),
        );
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('abortMultipart');
        $files->shouldNotReceive('existsCurrent');
        $files->shouldNotReceive('deleteCurrent');
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            Mockery::mock(DesignModelRegistrationService::class),
        );

        $this->expectException(\DomainException::class);
        $service->abort(8, 15, $uploadId);
    }

    public function test_complete_deletes_object_when_database_registration_fails(): void
    {
        Cache::flush();
        $uploadId = 'upload-registration-failure';
        $path = 'org-7/pir/model-uploads/user-15/upload-registration-failure/building.ifc';
        Cache::put(
            'design_management:model_upload:'.$uploadId,
            $this->multipartSession($uploadId, $path),
            now()->addHours(2),
        );
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $completion = new CurrentMultipartCompletion($path, 'object-etag', 1024, 'application/x-step');
        $stored = new CurrentStoredFile(
            $path,
            'object-etag',
            1024,
            hash('sha256', 'body'),
            'application/x-step',
        );
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('completeCurrentMultipart')->once()->andReturn($completion);
        $files->shouldReceive('verifyCurrentMultipart')->once()->andReturn($stored);
        $files->shouldReceive('deleteCurrent')->once()->with($path);
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('findPackage')->once()->with(7, 21)->andReturn($package);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->once()->with($package);
        $registrar->shouldReceive('registerStoredIfcModel')
            ->once()
            ->andThrow(new \DomainException('registration_failed'));
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        try {
            $service->complete(7, 15, $uploadId);
            $this->fail('Registration failure was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame('registration_failed', $exception->getMessage());
        }

        $this->assertNull(Cache::get('design_management:model_upload:'.$uploadId));
    }

    public function test_completion_cleanup_failure_keeps_cache_session_for_retry(): void
    {
        $uploadId = 'upload-cleanup-retry';
        $path = 'org-7/pir/model-uploads/user-15/upload-cleanup-retry/building.ifc';
        $session = $this->multipartSession($uploadId, $path);
        $package = new DesignPackage;
        $package->forceFill(['id' => 21, 'organization_id' => 7, 'project_id' => 11]);
        $completion = new CurrentMultipartCompletion($path, 'object-etag', 1024, 'application/x-step');
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('completeCurrentMultipart')->once()->andReturn($completion);
        $files->shouldNotReceive('verifyCurrentMultipart');
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturn(true);
        $files->shouldReceive('deleteCurrent')
            ->once()
            ->with($path)
            ->andThrow(new \RuntimeException('storage_unavailable'));
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('findPackage')->once()->with(7, 21)->andReturn($package);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->once()->with($package);
        Cache::shouldReceive('get')
            ->twice()
            ->with('design_management:model_upload:'.$uploadId)
            ->andReturn($session);
        Cache::shouldReceive('lock')->once()->andReturn(new class
        {
            public function block(int $seconds, callable $callback): mixed
            {
                return $callback();
            }
        });
        Cache::shouldReceive('put')->once()->andReturn(false);
        Cache::shouldNotReceive('forget');
        $service = new DesignModelMultipartUploadService(
            $files,
            new DesignStoragePathService,
            $registrar,
        );

        $this->expectException(\DomainException::class);
        $service->complete(7, 15, $uploadId);
    }

    private function multipartSession(string $uploadId, string $path): array
    {
        return [
            'upload_id' => $uploadId,
            's3_upload_id' => 'provider-'.$uploadId,
            'source_path' => $path,
            'organization_id' => 7,
            'project_id' => 11,
            'package_id' => 21,
            'user_id' => 15,
            'part_size_bytes' => 5_242_880,
            'parts_count' => 1,
            'file' => [
                'original_name' => 'building.ifc',
                'mime_type' => 'application/x-step',
                'size_bytes' => 1024,
            ],
            'uploaded_parts' => [
                1 => [
                    'PartNumber' => 1,
                    'ETag' => 'part-etag',
                    'Size' => 1024,
                    'ChecksumSHA256' => str_repeat('a', 64),
                ],
            ],
            'completion' => null,
            'expires_at' => now()->addHours(2)->toISOString(),
            'payload' => ['title' => 'IFC', 'version_number' => '1'],
        ];
    }
}
