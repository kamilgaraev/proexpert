<?php

namespace App\Services\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\Exceptions\VersionedObjectTransportException;
use Aws\Exception\AwsException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Получить диск S3 (всегда используем общий бакет).
     */
    public function disk(?Organization $organization = null): FilesystemAdapter|Filesystem
    {
        // Используем единый общий S3 бакет для всех организаций
        return Storage::disk('s3');
    }

    /** @return array{path:string,body:string,size:int,sha256:string,etag:?string,version_id:?string,content_type:string,created:bool} */
    public function putImmutable(string $path, string $body, string $contentType): array
    {
        try {
            $client = $this->s3Client();
            $config = $this->disk()->getConfig();
            $bucket = $config['bucket'] ?? null;
            if (! is_string($bucket) || $bucket === '') {
                throw new VersionedObjectTransportException('s3_conditional_put_unavailable');
            }
            $result = $client->putObject([
                'Bucket' => $bucket, 'Key' => $path, 'Body' => $body,
                'ContentType' => $contentType, 'IfNoneMatch' => '*',
            ]);
            $etag = is_string($result['ETag'] ?? null) ? trim($result['ETag'], '"') : null;
            $version = is_string($result['VersionId'] ?? null) ? $result['VersionId'] : null;
            if ($version === null || trim($version) === '') {
                throw new VersionedObjectIntegrityException('s3_bucket_versioning_required');
            }

            $this->tagEstimateGenerationObject($path, $version, true);

            return ['path' => $path, 'body' => $body, 'size' => strlen($body),
                'sha256' => hash('sha256', $body), 'etag' => $etag, 'version_id' => $version,
                'content_type' => $contentType, 'created' => true];
        } catch (AwsException $exception) {
            $status = $exception->getStatusCode();
            if (! in_array($status, [409, 412], true)) {
                throw $this->versionedAwsException($exception);
            }

            $existing = $this->describeVersion($path, null);
            $this->tagEstimateGenerationObject($path, $existing['version_id'], false);

            return [...$existing, 'created' => false];
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_conditional_put_unavailable', 0, $exception);
        } catch (VersionedObjectIntegrityException|VersionedObjectTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new VersionedObjectTransportException('s3_conditional_put_unavailable', 0, $exception);
        }
    }

    /** @return array{path:string,body:string,size:int,sha256:string,etag:?string,version_id:?string,content_type:string} */
    public function describeVersion(string $path, ?string $versionId, int $maxBytes = 64_000_000): array
    {
        try {
            $client = $this->s3Client();
            $bucket = $this->disk()->getConfig()['bucket'] ?? null;
            if (! is_string($bucket) || $bucket === '') {
                throw new VersionedObjectTransportException('s3_versioned_read_unavailable');
            }
        } catch (VersionedObjectTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new VersionedObjectTransportException('s3_versioned_read_unavailable', 0, $exception);
        }
        $arguments = ['Bucket' => $bucket, 'Key' => $path];
        if ($versionId !== null && $versionId !== '') {
            $arguments['VersionId'] = $versionId;
        }
        try {
            $head = $client->headObject($arguments);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        }
        $resolvedVersion = is_string($head['VersionId'] ?? null) ? $head['VersionId'] : $versionId;
        if ($resolvedVersion === null || trim($resolvedVersion) === '') {
            throw new VersionedObjectIntegrityException('s3_bucket_versioning_required');
        }
        $contentLength = $head['ContentLength'] ?? null;
        if (! is_numeric($contentLength) || (int) $contentLength < 0 || (int) $contentLength > $maxBytes) {
            throw new VersionedObjectIntegrityException('s3_object_size_invalid');
        }
        $arguments['VersionId'] = $resolvedVersion;
        try {
            $object = $client->getObject($arguments);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        }
        if (isset($object['VersionId']) && (string) $object['VersionId'] !== $resolvedVersion) {
            throw new VersionedObjectIntegrityException('s3_object_version_mismatch');
        }
        $stream = $object['Body'] ?? null;
        if (! is_object($stream) || ! method_exists($stream, 'read') || ! method_exists($stream, 'eof')) {
            throw new VersionedObjectIntegrityException('s3_object_stream_invalid');
        }
        $body = '';
        while (! $stream->eof()) {
            $remaining = $maxBytes + 1 - strlen($body);
            if ($remaining <= 0) {
                throw new VersionedObjectIntegrityException('s3_object_size_invalid');
            }
            $chunk = $stream->read(min(8192, $remaining));
            if (! is_string($chunk)) {
                throw new VersionedObjectIntegrityException('s3_object_stream_invalid');
            }
            $body .= $chunk;
        }
        if (strlen($body) !== (int) $contentLength) {
            throw new VersionedObjectIntegrityException('s3_object_size_mismatch');
        }

        return ['path' => $path, 'body' => $body, 'size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'etag' => is_string($head['ETag'] ?? null) ? trim($head['ETag'], '"') : null,
            'version_id' => $resolvedVersion,
            'content_type' => is_string($head['ContentType'] ?? null) ? $head['ContentType'] : 'application/octet-stream'];
    }

    private function versionedAwsException(
        AwsException $exception,
    ): VersionedObjectIntegrityException|VersionedObjectTransportException {
        $code = (string) $exception->getAwsErrorCode();
        if ($exception->getStatusCode() === 404 || in_array($code, ['NoSuchKey', 'NoSuchVersion', 'NotFound'], true)) {
            return new VersionedObjectIntegrityException('s3_pinned_object_unavailable', 0, $exception);
        }

        return new VersionedObjectTransportException('s3_versioned_object_transport_failed', 0, $exception);
    }

    /** @return array{size:int,version_id:string} */
    public function describeHead(string $path): array
    {
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('s3_versioned_read_unavailable');
        }
        $head = $this->s3Client()->headObject(['Bucket' => $bucket, 'Key' => $path]);
        $size = $head['ContentLength'] ?? null;
        $version = $head['VersionId'] ?? null;
        if (! is_numeric($size) || (int) $size < 1 || ! is_string($version) || trim($version) === '') {
            throw new \RuntimeException('s3_object_head_invalid');
        }

        return ['size' => (int) $size, 'version_id' => $version];
    }

    public function removeImmutable(string $path, ?string $versionId): void
    {
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('s3_versioned_delete_unavailable');
        }
        if ($versionId === null || trim($versionId) === '') {
            throw new \RuntimeException('s3_versioned_delete_requires_version');
        }
        $arguments = ['Bucket' => $bucket, 'Key' => $path, 'VersionId' => $versionId];
        $this->s3Client()->deleteObject($arguments);
    }

    protected function s3Client(): \Aws\S3\S3ClientInterface
    {
        $adapter = $this->disk()->getAdapter();
        if (! method_exists($adapter, 'getClient')) {
            throw new \RuntimeException('s3_conditional_put_unavailable');
        }
        $client = $adapter->getClient();
        if (! $client instanceof \Aws\S3\S3ClientInterface) {
            throw new \RuntimeException('s3_conditional_put_unavailable');
        }

        return $client;
    }

    private function tagEstimateGenerationObject(
        string $path,
        ?string $versionId = null,
        bool $deleteOnFailure = false,
    ): void {
        if (preg_match('#^org-[1-9][0-9]*/estimate-generation(?:/|$)#D', $path) !== 1) {
            return;
        }

        $client = $this->s3Client();
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('s3_object_tagging_unavailable');
        }

        if ($versionId === null || trim($versionId) === '') {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $path]);
            $versionId = is_string($head['VersionId'] ?? null) ? trim($head['VersionId']) : null;
        }
        if ($versionId === null || $versionId === '') {
            throw new \RuntimeException('s3_bucket_versioning_required');
        }

        try {
            $client->putObjectTagging([
                'Bucket' => $bucket,
                'Key' => $path,
                'VersionId' => $versionId,
                'Tagging' => [
                    'TagSet' => [['Key' => 'most-module', 'Value' => 'estimate-generation']],
                ],
            ]);
        } catch (\Throwable $exception) {
            if ($deleteOnFailure) {
                try {
                    $client->deleteObject([
                        'Bucket' => $bucket,
                        'Key' => $path,
                        'VersionId' => $versionId,
                    ]);
                } catch (\Throwable) {
                }
            }

            throw new \RuntimeException('s3_object_tagging_failed', 0, $exception);
        }
    }

    /**
     * Загрузить файл и вернуть путь или false.
     */
    public function upload(
        UploadedFile $file,
        string $directory,
        ?string $existingPath = null,
        string $visibility = 'public',
        ?Organization $organization = null,
        bool $respectRequestedVisibility = false,
        bool $privacyMode = false,
    ): string|false {
        $disk = $this->disk($organization);

        // Получаем организацию для формирования пути
        $org = $this->getOrganization($organization);

        // Для Яндекс S3 с организациями используем private доступ (временные URL)
        $useVisibility = $visibility;
        if ($organization && ! $respectRequestedVisibility) {
            $useVisibility = null; // private по умолчанию
        }

        // Пытаемся удалить старый файл если он существует
        if ($existingPath) {
            try {
                $disk->delete($existingPath);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to delete previous file', [
                    'path' => $existingPath,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        // Формируем путь с префиксом организации: org-{id}/directory/filename
        $orgPrefix = $org ? "org-{$org->id}" : 'shared';
        $fullPath = $orgPrefix.'/'.$directory.'/'.$filename;
        $logFilename = $privacyMode ? hash('sha256', $file->getClientOriginalName()) : $file->getClientOriginalName();
        $logStoragePath = $privacyMode ? 'redacted' : $fullPath;
        $logGeneratedFilename = $privacyMode ? 'redacted' : $filename;
        $logDirectory = $privacyMode ? 'redacted' : $directory;

        $startTime = microtime(true);
        $fileSizeMb = round($file->getSize() / 1024 / 1024, 2);

        // TECHNICAL: Начало загрузки файла в S3
        $this->logging->technical('s3.upload.started', [
            'filename' => $logFilename,
            'original_name' => $logFilename,
            'generated_filename' => $logGeneratedFilename,
            'file_size_mb' => $fileSizeMb,
            'mime_type' => $file->getClientMimeType(),
            'organization_id' => $org?->id,
            'directory' => $logDirectory,
            'full_s3_path' => $logStoragePath,
            'visibility' => $visibility,
            'org_prefix' => $orgPrefix,
        ]);

        try {
            // Проверяем валидность файла перед загрузкой
            if (! $file->isValid()) {
                Log::error('[FileService] upload(): file is not valid', [
                    'filename' => $logFilename,
                    'error' => $file->getError(),
                    'error_message' => $file->getErrorMessage(),
                ]);

                $this->logging->technical('s3.upload.failed', [
                    'filename' => $logFilename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $logStoragePath,
                    'organization_id' => $org?->id,
                    'reason' => 'UploadedFile is not valid',
                    'upload_error' => $file->getError(),
                ], 'error');

                return false;
            }

            Log::info('[FileService] upload(): starting upload', [
                'org_prefix' => $orgPrefix,
                'directory' => $logDirectory,
                'filename' => $logGeneratedFilename,
                'full_path' => $logStoragePath,
                'org_id' => $org?->id,
                'visibility' => $visibility,
                'use_visibility' => $useVisibility,
                'file_is_valid' => $file->isValid(),
                'file_real_path' => $privacyMode ? 'redacted' : $file->getRealPath(),
            ]);

            // Получаем путь к временному файлу
            $realPath = $file->getRealPath();
            $logRealPath = $privacyMode ? 'redacted' : $realPath;

            if (! $realPath || ! file_exists($realPath)) {
                Log::error('[FileService] upload(): file path is invalid or file does not exist', [
                    'real_path' => $logRealPath,
                    'filename' => $logFilename,
                    'is_uploaded_file' => is_uploaded_file($realPath),
                ]);

                $this->logging->technical('s3.upload.failed', [
                    'filename' => $logFilename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $logStoragePath,
                    'organization_id' => $org?->id,
                    'reason' => 'File path is invalid or file does not exist',
                    'real_path' => $logRealPath,
                ], 'error');

                return false;
            }

            // Используем полный путь для загрузки
            $fileContent = file_get_contents($realPath);

            // Проверяем, что контент успешно получен
            if ($fileContent === false || strlen($fileContent) === 0) {
                Log::error('[FileService] upload(): failed to read file content', [
                    'real_path' => $logRealPath,
                    'file_size' => filesize($realPath),
                    'file_exists' => file_exists($realPath),
                    'is_readable' => is_readable($realPath),
                    'content_length' => $fileContent === false ? 'false' : strlen($fileContent),
                ]);

                $this->logging->technical('s3.upload.failed', [
                    'filename' => $logFilename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $logStoragePath,
                    'organization_id' => $org?->id,
                    'reason' => 'Failed to read file content or content is empty',
                    'real_path' => $logRealPath,
                ], 'error');

                return false;
            }

            Log::info('[FileService] File content prepared', [
                'file_size' => strlen($fileContent),
                'file_path' => $logRealPath,
                'content_length' => strlen($fileContent),
                'expected_size' => $file->getSize(),
            ]);

            // Логируем конфигурацию диска
            $diskConfig = $disk->getConfig();
            Log::info('[FileService] Disk config', [
                'driver' => $diskConfig['driver'] ?? 'unknown',
                'bucket' => $diskConfig['bucket'] ?? 'unknown',
                'endpoint' => $diskConfig['endpoint'] ?? 'unknown',
                'region' => $diskConfig['region'] ?? 'unknown',
            ]);

            // Всегда передаем явный visibility параметр для Yandex S3
            // Если $useVisibility null, используем 'private' по умолчанию
            $visibilityParam = $useVisibility ?? 'private';

            try {

                Log::info('[FileService] Calling disk->put()', [
                    'path' => $logStoragePath,
                    'content_length' => strlen($fileContent),
                    'visibility' => $visibilityParam,
                ]);

                $result = $disk->put($fullPath, $fileContent, $visibilityParam);
                if ($result === true) {
                    $this->tagEstimateGenerationObject($fullPath);
                }

                Log::info('[FileService] disk->put() result', [
                    'result' => $result,
                    'result_type' => gettype($result),
                    'path' => $logStoragePath,
                ]);

            } catch (\Exception $e) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);

                // TECHNICAL: Критическая ошибка загрузки в S3
                $this->logging->technical('s3.upload.failed', [
                    'filename' => $logFilename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $logStoragePath,
                    'organization_id' => $org?->id,
                    'duration_ms' => $durationMs,
                    'exception_class' => get_class($e),
                    'exception_message' => $privacyMode ? 'redacted' : $e->getMessage(),
                    'aws_error_code' => $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : null,
                    'trace' => $privacyMode ? 'redacted' : $e->getTraceAsString(),
                ], 'error');

                Log::error('[FileService] S3 put() exception', [
                    'path' => $logStoragePath,
                    'error' => $privacyMode ? 'redacted' : $e->getMessage(),
                    'exception_class' => get_class($e),
                    'trace' => $privacyMode ? 'redacted' : $e->getTraceAsString(),
                ]);

                return false;
            }

            if ($result) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);

                // TECHNICAL: Успешная загрузка файла в S3
                $this->logging->technical('s3.upload.success', [
                    'filename' => $logFilename,
                    'generated_filename' => $logGeneratedFilename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $logStoragePath,
                    'organization_id' => $org?->id,
                    'duration_ms' => $durationMs,
                    'upload_speed_mbps' => $durationMs > 0 ? round(($fileSizeMb * 8 * 1000) / $durationMs, 2) : null,
                    'visibility' => $visibility,
                    'directory' => $logDirectory,
                ]);

                // BUSINESS: Загрузка файла - важная бизнес-метрика использования хранилища
                $this->logging->business('file.uploaded', [
                    'filename' => $logFilename,
                    'file_size_mb' => $fileSizeMb,
                    'organization_id' => $org?->id,
                    'directory' => $logDirectory,
                    'user_id' => Auth::id(),
                ]);

                Log::info('[FileService] upload(): file uploaded successfully', [
                    'path' => $logStoragePath,
                    'org_id' => $org?->id,
                    'visibility' => $useVisibility,
                ]);

                return $fullPath;
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            // TECHNICAL: S3 put вернул false
            $this->logging->technical('s3.upload.put_failed', [
                'filename' => $logFilename,
                'file_size_mb' => $fileSizeMb,
                's3_path' => $logStoragePath,
                'organization_id' => $org?->id,
                'duration_ms' => $durationMs,
                'result' => false,
                'content_length' => strlen($fileContent),
                'visibility_used' => $visibilityParam,
                'disk_driver' => $diskConfig['driver'] ?? 'unknown',
                'bucket' => $diskConfig['bucket'] ?? 'unknown',
            ], 'error');

            Log::error('[FileService] upload(): put returned false', [
                'path' => $logStoragePath,
                'content_length' => strlen($fileContent),
                'visibility_used' => $visibilityParam,
                'disk_driver' => $diskConfig['driver'] ?? 'unknown',
                'bucket' => $diskConfig['bucket'] ?? 'unknown',
                'endpoint' => $diskConfig['endpoint'] ?? 'unknown',
                'file_real_path' => $logRealPath,
                'file_exists' => file_exists($realPath),
            ]);

            return false;
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            // TECHNICAL: Общая ошибка загрузки
            $this->logging->technical('s3.upload.exception', [
                'filename' => $logFilename,
                'file_size_mb' => $fileSizeMb,
                's3_path' => $logStoragePath,
                'organization_id' => $org?->id,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'exception_message' => $privacyMode ? 'redacted' : $e->getMessage(),
                'file_path' => $e->getFile(),
                'line' => $e->getLine(),
            ], 'error');

            Log::error('[FileService] upload(): failed', [
                'path' => $logStoragePath,
                'error' => $privacyMode ? 'redacted' : $e->getMessage(),
                'trace' => $privacyMode ? 'redacted' : $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function delete(?string $path, ?Organization $organization = null): bool
    {
        if (! $path) {
            return true;
        }

        $startTime = microtime(true);

        // TECHNICAL: Начало удаления файла из S3
        $this->logging->technical('s3.delete.started', [
            's3_path' => $path,
            'organization_id' => $organization?->id,
        ]);

        try {
            $disk = $this->disk($organization);
            $result = $disk->delete($path);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                // TECHNICAL: Успешное удаление файла
                $this->logging->technical('s3.delete.success', [
                    's3_path' => $path,
                    'organization_id' => $organization?->id,
                    'duration_ms' => $durationMs,
                ]);
            } else {
                // TECHNICAL: Удаление вернуло false
                $this->logging->technical('s3.delete.failed', [
                    's3_path' => $path,
                    'organization_id' => $organization?->id,
                    'duration_ms' => $durationMs,
                    'result' => false,
                ], 'warning');
            }

            return $result;
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            // TECHNICAL: Ошибка при удалении файла
            $this->logging->technical('s3.delete.exception', [
                's3_path' => $path,
                'organization_id' => $organization?->id,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ], 'error');

            \Illuminate\Support\Facades\Log::warning('Delete file failed', [
                'path' => $path,
                'err' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function putContent(
        string $content,
        string $directory,
        string $filename,
        string $visibility = 'private',
        ?Organization $organization = null
    ): string|false {
        $org = $this->getOrganization($organization);
        $orgPrefix = $org ? "org-{$org->id}" : 'shared';
        $safeDirectory = trim($directory, '/');
        $safeFilename = trim($filename, '/');
        $fullPath = "{$orgPrefix}/{$safeDirectory}/{$safeFilename}";

        try {
            $stored = $this->disk($organization)->put($fullPath, $content, $visibility);

            if ($stored !== true) {
                Log::error('[FileService] putContent(): put returned false', [
                    'path' => $fullPath,
                    'organization_id' => $org?->id,
                ]);

                return false;
            }

            $this->tagEstimateGenerationObject($fullPath);

            return $fullPath;
        } catch (\Throwable $e) {
            Log::error('[FileService] putContent(): failed', [
                'path' => $fullPath,
                'organization_id' => $org?->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function url(?string $path, ?Organization $organization = null): ?string
    {
        if (! $path) {
            return null;
        }
        $disk = $this->disk($organization);
        try {
            $url = $disk->url($path);
        } catch (\Throwable $e) {
            Log::warning('[FileService] url() failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        Log::debug('[FileService] url(): generated', [
            'path' => $path,
            'url' => $url,
        ]);

        return $url;
    }

    public function setVisibility(?string $path, string $visibility, ?Organization $organization = null): bool
    {
        if (! $path) {
            return false;
        }

        $disk = $this->disk($organization);

        try {
            $disk->setVisibility($path, $visibility);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[FileService] setVisibility() failed', [
                'path' => $path,
                'visibility' => $visibility,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function publicUrl(?string $path, ?Organization $organization = null): ?string
    {
        if (! $path) {
            return null;
        }

        $this->setVisibility($path, 'public', $organization);

        return $this->url($path, $organization);
    }

    /** @param array<string, string> $responseParameters */
    public function temporaryUrl(
        ?string $path,
        int $minutes = 5,
        ?Organization $organization = null,
        array $responseParameters = [],
    ): ?string {
        if (! $path) {
            return null;
        }
        $disk = $this->disk($organization);
        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes($minutes), $responseParameters);
        } catch (\Throwable $e) {
            Log::warning('[FileService] temporaryUrl() failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $url;
    }

    /**
     * Получить организацию для определения префикса пути.
     */
    private function getOrganization(?Organization $organization = null): ?Organization
    {
        $org = $organization;
        if (! $org) {
            $org = Auth::user()?->currentOrganization;
        }
        // Фолбек на статический контекст, который уже выставлен middleware
        if (! $org) {
            $org = \App\Services\Organization\OrganizationContext::getOrganization();
        }

        return $org;
    }
}
