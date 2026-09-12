<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcUploadSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelRegistrationService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelMultipartUploadService;
use App\BusinessModules\Features\DesignManagement\Services\DesignStoragePathService;
use App\Services\Storage\DTO\CurrentMultipartCompletion;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\FileService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class DesignModelMultipartUploadServiceTest extends TestCase
{

    public function test_uploads_api_chunk_to_s3_multipart_upload(): void
    {
        Cache::flush();

        $uploadId = '00000000-0000-4000-8000-000000000123';
        Cache::put('design_management:model_upload:'.$uploadId, [
            'upload_id' => $uploadId,
            's3_upload_id' => 's3-upload-123',
            'source_path' => 'org-7/pir/model-uploads/user-15/00000000-0000-4000-8000-000000000123/building.ifc',
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
            'expires_at' => now()->addDay()->toISOString(),
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
                    === 'org-7/pir/model-uploads/user-15/00000000-0000-4000-8000-000000000123/building.ifc'
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
                    'org-7/pir/model-uploads/user-15/00000000-0000-4000-8000-000000000123/building.ifc',
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
        $package = $this->persistedPackage();
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

    public function test_start_resumes_durable_session_for_same_file_identity(): void
    {
        Cache::flush();
        $package = $this->persistedPackage();
        $registrar = Mockery::mock(DesignModelRegistrationService::class);
        $registrar->shouldReceive('ensurePackageAcceptsModelChanges')->twice()->with($package);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('startMultipart')->once()->andReturnUsing(
            static fn (string $path, string $mime, int $partSize, array $metadata): MultipartUpload => new MultipartUpload($path, 'provider-resume', $mime, $partSize, $metadata)
        );
        $service = new DesignModelMultipartUploadService($files, new DesignStoragePathService, $registrar);
        $payload = [
            'file_size_bytes' => 1024,
            'original_name' => 'building.ifc',
            'content_type' => 'application/x-step',
            'file_sha256' => str_repeat('a', 64),
            'title' => 'IFC',
            'version_number' => '1',
        ];

        $started = $service->start($package, 15, $payload);
        Cache::forget('design_management:model_upload:'.$started['upload_id']);
        $resumed = $service->start($package, 15, $payload);

        $this->assertSame($started['upload_id'], $resumed['upload_id']);
        $this->assertSame(false, $resumed['parts'][0]['uploaded']);
        $this->assertDatabaseCount('design_ifc_upload_sessions', 1);
    }

    public function test_expired_uncompleted_session_is_aborted_and_marked_expired(): void
    {
        $uploadId = '00000000-0000-4000-8000-000000000193';
        $path = "org-7/pir/model-uploads/user-15/{$uploadId}/building.ifc";
        $this->multipartSession($uploadId, $path);
        DesignIfcUploadSession::query()->whereKey($uploadId)->update(['expires_at' => now()->subMinute()]);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('abortMultipart')->once()->with(Mockery::on(
            static fn (MultipartUpload $upload): bool => $upload->uploadId === 'provider-00000000-0000-4000-8000-000000000193'
        ));
        $registrar = Mockery::mock(DesignModelRegistrationService::class);

        (new DesignModelMultipartUploadService($files, new DesignStoragePathService, $registrar))
            ->cleanupExpiredSessions(7, 15);

        $this->assertSame('expired', DesignIfcUploadSession::query()->findOrFail($uploadId)->status);
    }

    public function test_repeat_complete_returns_durably_registered_version_without_storage_calls(): void
    {
        $version = $this->persistedVersion();
        $uploadId = '00000000-0000-4000-8000-000000000212';
        $this->multipartSession($uploadId, "org-7/pir/model-uploads/user-15/{$uploadId}/building.ifc");
        DesignIfcUploadSession::query()->whereKey($uploadId)->update([
            'status' => 'completed',
            'completed_version_id' => $version->id,
        ]);
        $files = Mockery::mock(FileService::class);
        $registrar = Mockery::mock(DesignModelRegistrationService::class);

        $result = (new DesignModelMultipartUploadService($files, new DesignStoragePathService, $registrar))
            ->complete(7, 15, $uploadId);

        $this->assertSame($version->id, $result->id);
    }

    public function test_start_aborts_provider_upload_when_cache_rejects_session(): void
    {
        $package = $this->persistedPackage();
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
        $package = $this->persistedPackage();
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
        \Illuminate\Support\Facades\Log::shouldReceive('error')->once()->with(
            'design_management.multipart_storage_failed',
            ['causes' => [['class' => \RuntimeException::class, 'code' => '0', 'reason' => 'cache_unavailable', 'storage_code' => null]]],
        );
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
        $uploadId = '00000000-0000-4000-8000-000000000295';
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
        $uploadId = '00000000-0000-4000-8000-000000000335';
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
        $uploadId = '00000000-0000-4000-8000-000000000426';
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
        $uploadId = '00000000-0000-4000-8000-000000000453';
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
        $uploadId = '00000000-0000-4000-8000-000000000481';
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
        $uploadId = '00000000-0000-4000-8000-000000000505';
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
        $uploadId = '00000000-0000-4000-8000-000000000550';
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
        Cache::shouldReceive('forget')->never();
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
        $session = [
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
        $this->persistedPackage();
        DesignIfcUploadSession::query()->create([
            'id' => $uploadId,
            'organization_id' => 7,
            'project_id' => 11,
            'package_id' => 21,
            'user_id' => 15,
            'file_identity' => hash('sha256', $uploadId),
            's3_upload_id' => $session['s3_upload_id'],
            'source_path' => $path,
            'original_name' => 'building.ifc',
            'mime_type' => 'application/x-step',
            'size_bytes' => 1024,
            'part_size_bytes' => 5_242_880,
            'parts_count' => 1,
            'uploaded_parts' => $session['uploaded_parts'],
            'completion' => null,
            'payload' => $session['payload'],
            'status' => 'active',
            'expires_at' => now()->addHours(2),
        ]);

        return $session;
    }

    private function persistedPackage(): DesignPackage
    {
        $existing = DesignPackage::query()->find(21);
        if ($existing instanceof DesignPackage) {
            return $existing;
        }
        $organization = Organization::factory()->make();
        $organization->forceFill(['id' => 7])->save();
        $user = User::factory()->make();
        $user->forceFill(['id' => 15])->save();
        $project = Project::factory()->make(['organization_id' => 7]);
        $project->forceFill(['id' => 11])->save();

        $package = new DesignPackage;
        $package->forceFill([
            'id' => 21,
            'organization_id' => 7,
            'project_id' => 11,
            'created_by' => 15,
            'updated_by' => 15,
            'title' => 'IFC package',
            'status' => 'draft',
            'metadata' => [],
        ]);
        $package->save();

        return $package;
    }

    private function persistedVersion(): DesignArtifactVersion
    {
        $package = $this->persistedPackage();
        $artifact = $package->artifacts()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'created_by' => 15,
            'updated_by' => 15,
            'artifact_type' => 'model',
            'title' => 'IFC',
            'status' => 'active',
            'metadata' => [],
        ]);

        return $artifact->versions()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'created_by' => 15,
            'updated_by' => 15,
            'uploaded_by' => 15,
            'title' => 'IFC',
            'version_number' => '1',
            'source_format' => 'ifc',
            'file_format' => 'ifc',
            'source_file_path' => 'org-7/source/building.ifc',
            'source_original_name' => 'building.ifc',
            'source_mime_type' => 'application/x-step',
            'source_size_bytes' => 1024,
            'status' => 'uploaded',
            'metadata' => [],
        ]);
    }
}
