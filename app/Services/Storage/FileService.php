<?php

namespace App\Services\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\DTO\CurrentMultipartCompletion;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\DTO\TemporaryFileLink;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\Exceptions\VersionedObjectTransportException;
use Aws\Exception\AwsException;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileService
{
    private const REPORT_METADATA_KEYS = [
        'organization_id',
        'export_id',
        'export_hash',
        'run_id',
        'result_hash',
        'snapshot_id',
        'snapshot_classification',
        'data_classification',
        'contract_version',
        'formula_version',
        'source_schema_version',
        'renderer_version',
    ];

    private const S3_CONNECT_TIMEOUT_SECONDS = 5.0;

    private const S3_REQUEST_TIMEOUT_SECONDS = 60.0;

    private const MAX_TEMPORARY_URL_TTL_SECONDS = 3600;

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

    public function putPrivate(
        string $key,
        mixed $contents,
        string $mime,
        string $sha256,
    ): CurrentStoredFile {
        $this->assertOrganizationPath($key);
        $this->assertSafeStorageString($mime, 255, 'storage_object_invalid');
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('storage_object_checksum_invalid');
        }
        [$sizeBytes, $calculatedChecksum] = $this->describeCurrentContents($contents);
        if (! hash_equals($sha256, $calculatedChecksum)) {
            throw new \InvalidArgumentException('storage_object_checksum_mismatch');
        }

        $result = $this->s3Client()->putObject([
            'Bucket' => $this->reportBucket(),
            'Key' => $key,
            'Body' => $contents,
            'ACL' => 'private',
            'ContentType' => $mime,
            'Metadata' => ['sha256' => $sha256],
            'IfNoneMatch' => '*',
            '@http' => $this->s3HttpOptions(),
        ]);
        $etag = is_string($result['ETag'] ?? null)
            ? trim($result['ETag'], " \t\n\r\0\x0B\"")
            : '';
        if ($etag === '') {
            throw new \RuntimeException('s3_object_identity_invalid');
        }

        return new CurrentStoredFile($key, $etag, $sizeBytes, $sha256, $mime);
    }

    public function temporaryDownloadUrl(string $key, int $ttlSeconds): string
    {
        $this->assertOrganizationPath($key);
        if ($ttlSeconds < 1 || $ttlSeconds > self::MAX_TEMPORARY_URL_TTL_SECONDS) {
            throw new \InvalidArgumentException('storage_temporary_url_ttl_invalid');
        }

        $url = $this->disk()->temporaryUrl(
            $key,
            new DateTimeImmutable("+{$ttlSeconds} seconds"),
        );
        if (
            ! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new \RuntimeException('storage_temporary_url_invalid');
        }

        return $url;
    }

    /** @return resource */
    public function readCurrent(string $key)
    {
        $this->assertOrganizationPath($key);
        $stream = $this->disk()->readStream($key);
        if (! is_resource($stream)) {
            throw new \RuntimeException('storage_object_read_failed');
        }

        return $stream;
    }

    /** @return array<int, string> */
    public function listCurrent(string $prefix): array
    {
        $this->assertOrganizationPath($prefix);

        return array_values($this->disk()->allFiles($prefix));
    }

    public function existsCurrent(string $key): bool
    {
        $this->assertOrganizationPath($key);

        return $this->disk()->exists($key);
    }

    public function deleteCurrent(string $key): void
    {
        $this->assertOrganizationPath($key);
        if ($this->disk()->delete($key) !== true) {
            throw new \RuntimeException('storage_object_delete_failed');
        }
    }

    public function startMultipart(
        string $organizationPath,
        string $mime,
        int $partSizeBytes,
        array $metadata,
    ): MultipartUpload {
        $this->assertOrganizationPath($organizationPath);
        $this->assertSafeStorageString($mime, 255, 'multipart_upload_invalid');
        if (
            $partSizeBytes < MultipartUpload::MIN_PART_SIZE_BYTES
            || $partSizeBytes > MultipartUpload::MAX_PART_SIZE_BYTES
        ) {
            throw new \InvalidArgumentException('multipart_upload_invalid');
        }
        $this->assertStorageMetadata($metadata);

        try {
            $result = $this->reportS3Client()->createMultipartUpload([
                'Bucket' => $this->reportBucket(),
                'Key' => $organizationPath,
                'ContentType' => $mime,
                'Metadata' => $metadata,
                '@http' => $this->s3HttpOptions(),
            ]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_multipart_unavailable', 0, $exception);
        }

        $uploadId = is_string($result['UploadId'] ?? null) ? trim($result['UploadId']) : '';
        if ($uploadId === '' || strtolower($uploadId) === 'null') {
            throw new VersionedObjectIntegrityException('s3_multipart_identity_invalid');
        }
        if (
            isset($result['Key'])
            && (! is_string($result['Key']) || ! hash_equals($organizationPath, $result['Key']))
        ) {
            throw new VersionedObjectIntegrityException('s3_multipart_identity_invalid');
        }

        return new MultipartUpload($organizationPath, $uploadId, $mime, $partSizeBytes, $metadata);
    }

    public function uploadPart(
        MultipartUpload $upload,
        int $partNumber,
        string $bytes,
        string $checksumSha256,
    ): MultipartPart {
        if (
            $partNumber < 1
            || $partNumber > 10000
            || $bytes === ''
            || strlen($bytes) > $upload->partSizeBytes
            || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1
        ) {
            throw new \InvalidArgumentException('multipart_part_invalid');
        }
        if (! hash_equals($checksumSha256, hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('multipart_part_checksum_mismatch');
        }

        try {
            $result = $this->reportS3Client()->uploadPart([
                'Bucket' => $this->reportBucket(),
                'Key' => $upload->organizationPath,
                'UploadId' => $upload->uploadId,
                'PartNumber' => $partNumber,
                'Body' => $bytes,
                'ContentLength' => strlen($bytes),
                '@http' => $this->s3HttpOptions(),
            ]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_multipart_unavailable', 0, $exception);
        }

        $etag = is_string($result['ETag'] ?? null) ? trim($result['ETag']) : '';
        if ($etag === '') {
            throw new VersionedObjectIntegrityException('s3_multipart_part_identity_invalid');
        }

        return new MultipartPart(
            $upload->organizationPath,
            $upload->uploadId,
            $partNumber,
            $etag,
            strlen($bytes),
            $checksumSha256,
        );
    }

    public function completeMultipart(
        MultipartUpload $upload,
        array $orderedParts,
        array $conditions,
    ): StoredFile {
        $sizeBytes = $this->assertMultipartParts($upload, $orderedParts);
        $checksumSha256 = $this->assertMultipartConditions($conditions);
        $completedParts = array_map(
            static fn (MultipartPart $part): array => [
                'PartNumber' => $part->number,
                'ETag' => $part->etag,
            ],
            $orderedParts,
        );

        try {
            $result = $this->reportS3Client()->completeMultipartUpload([
                'Bucket' => $this->reportBucket(),
                'Key' => $upload->organizationPath,
                'UploadId' => $upload->uploadId,
                'MultipartUpload' => ['Parts' => $completedParts],
                'IfNoneMatch' => '*',
                '@http' => $this->s3HttpOptions(),
            ]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_multipart_unavailable', 0, $exception);
        }

        $etag = is_string($result['ETag'] ?? null) ? trim($result['ETag'], " \t\n\r\0\x0B\"") : '';
        if (
            $etag === ''
            || (isset($result['Key'])
                && (! is_string($result['Key'])
                    || ! hash_equals($upload->organizationPath, $result['Key'])))
            || (isset($result['Bucket'])
                && (! is_string($result['Bucket'])
                    || ! hash_equals($this->reportBucket(), $result['Bucket'])))
        ) {
            throw new VersionedObjectIntegrityException('s3_multipart_completion_identity_invalid');
        }

        return new StoredFile(
            $upload->organizationPath,
            $etag,
            $sizeBytes,
            $checksumSha256,
            $upload->mime,
        );
    }

    public function completeCurrentMultipart(
        MultipartUpload $upload,
        array $orderedParts,
        int $expectedSizeBytes,
    ): CurrentMultipartCompletion {
        $sizeBytes = $this->assertMultipartParts($upload, $orderedParts);
        if ($expectedSizeBytes < 1 || $sizeBytes !== $expectedSizeBytes) {
            throw new \InvalidArgumentException('multipart_parts_size_mismatch');
        }
        $completedParts = array_map(
            static fn (MultipartPart $part): array => [
                'PartNumber' => $part->number,
                'ETag' => $part->etag,
            ],
            $orderedParts,
        );

        try {
            $result = $this->reportS3Client()->completeMultipartUpload([
                'Bucket' => $this->reportBucket(),
                'Key' => $upload->organizationPath,
                'UploadId' => $upload->uploadId,
                'MultipartUpload' => ['Parts' => $completedParts],
                'IfNoneMatch' => '*',
                '@http' => $this->s3HttpOptions(),
            ]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_multipart_unavailable', 0, $exception);
        }

        $etag = is_string($result['ETag'] ?? null)
            ? trim($result['ETag'], " \t\n\r\0\x0B\"")
            : '';
        if (
            $etag === ''
            || (isset($result['Key'])
                && (! is_string($result['Key'])
                    || ! hash_equals($upload->organizationPath, $result['Key'])))
            || (isset($result['Bucket'])
                && (! is_string($result['Bucket'])
                    || ! hash_equals($this->reportBucket(), $result['Bucket'])))
        ) {
            throw new VersionedObjectIntegrityException('s3_multipart_completion_identity_invalid');
        }

        return new CurrentMultipartCompletion(
            $upload->organizationPath,
            $etag,
            $expectedSizeBytes,
            $upload->mime,
        );
    }

    public function verifyCurrentMultipart(CurrentMultipartCompletion $completion): CurrentStoredFile
    {
        $stream = $this->readCurrent($completion->key);
        try {
            [$streamedSizeBytes, $checksumSha256] = $this->hashReadStream($stream);
        } finally {
            fclose($stream);
        }
        if ($streamedSizeBytes !== $completion->sizeBytes) {
            $this->deleteInvalidCurrentObject($completion->key);

            throw new VersionedObjectIntegrityException('s3_multipart_current_object_size_mismatch');
        }

        return new CurrentStoredFile(
            $completion->key,
            $completion->etag,
            $streamedSizeBytes,
            $checksumSha256,
            $completion->mime,
        );
    }

    public function abortMultipart(MultipartUpload $upload): void
    {
        try {
            $this->reportS3Client()->abortMultipartUpload([
                'Bucket' => $this->reportBucket(),
                'Key' => $upload->organizationPath,
                'UploadId' => $upload->uploadId,
                '@http' => $this->s3HttpOptions(),
            ]);
        } catch (AwsException $exception) {
            if (
                $exception->getStatusCode() === 404
                || in_array($exception->getAwsErrorCode(), ['NoSuchUpload', 'NotFound'], true)
            ) {
                return;
            }

            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_multipart_unavailable', 0, $exception);
        }
    }

    public function headCurrent(string $organizationPath, int $maxBytes = 64_000_000): StoredFile
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('s3_object_size_invalid');
        }

        $description = $this->describeCurrent($organizationPath, -$maxBytes);
        if (
            ! is_string($description['etag'])
            || $description['etag'] === ''
            || $description['size'] < 1
        ) {
            throw new VersionedObjectIntegrityException('s3_object_identity_mismatch');
        }

        return new StoredFile(
            $organizationPath,
            $description['etag'],
            $description['size'],
            $description['sha256'],
            $description['content_type'],
        );
    }

    public function createTemporaryLink(
        string $organizationPath,
        int $ttlSeconds,
    ): TemporaryFileLink {
        $this->assertOrganizationPath($organizationPath);
        if ($ttlSeconds < 1 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('temporary_link_ttl_invalid');
        }

        $expiresAt = new DateTimeImmutable('+'.$ttlSeconds.' seconds');
        try {
            $command = $this->reportS3Client()->getCommand('GetObject', [
                'Bucket' => $this->reportBucket(),
                'Key' => $organizationPath,
            ]);
            $request = $this->reportS3Client()->createPresignedRequest($command, $expiresAt);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_presigned_link_unavailable', 0, $exception);
        }

        return new TemporaryFileLink((string) $request->getUri(), $organizationPath, $expiresAt);
    }

    /** @return array{path:string,body:string,size:int,sha256:string,etag:?string,content_type:string,created:bool} */
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
                '@http' => $this->s3HttpOptions(),
            ]);
            $etag = is_string($result['ETag'] ?? null) ? trim($result['ETag'], '"') : null;
            if ($etag === null || trim($etag) === '') {
                throw new VersionedObjectIntegrityException('s3_object_identity_invalid');
            }

            $this->tagEstimateGenerationObject($path, true);

            return ['path' => $path, 'body' => $body, 'size' => strlen($body),
                'sha256' => hash('sha256', $body), 'etag' => $etag,
                'content_type' => $contentType, 'created' => true];
        } catch (AwsException $exception) {
            $status = $exception->getStatusCode();
            if (! in_array($status, [409, 412], true)) {
                throw $this->versionedAwsException($exception);
            }

            $existing = $this->describeCurrent($path);
            $this->tagEstimateGenerationObject($path, false);

            return [...$existing, 'created' => false];
        } catch (\InvalidArgumentException $exception) {
            throw new VersionedObjectTransportException('s3_conditional_put_unavailable', 0, $exception);
        } catch (VersionedObjectIntegrityException|VersionedObjectTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new VersionedObjectTransportException('s3_conditional_put_unavailable', 0, $exception);
        }
    }

    /**
     * A negative max size verifies the current object without retaining the response body.
     * Its absolute value is the maximum accepted object size.
     *
     * @return array{path:string,body:string,size:int,sha256:string,etag:?string,content_type:string,metadata?:array<string,string>}
     */
    public function describeCurrent(
        string $path,
        int $maxBytes = 64_000_000,
    ): array {
        return $this->describeCurrentInternal($path, $maxBytes, $maxBytes > 0);
    }

    /** @return array{path:string,body:string,size:int,sha256:string,etag:?string,content_type:string,metadata?:array<string,string>} */
    private function describeCurrentInternal(
        string $path,
        int $maxBytes,
        bool $includeBody,
    ): array {
        if ($maxBytes === 0 || $maxBytes === PHP_INT_MIN) {
            throw new \InvalidArgumentException('s3_object_size_invalid');
        }
        $streamLimitBytes = $maxBytes < 0 ? abs($maxBytes) : $maxBytes;
        $reportObject = preg_match('#^org-[1-9][0-9]*/reports(?:/|$)#D', $path) === 1;
        $organizationId = null;
        if ($reportObject) {
            $organizationId = $this->assertOrganizationPath($path);
        }
        try {
            $client = $reportObject ? $this->reportS3Client() : $this->s3Client();
            $bucket = $reportObject ? $this->reportBucket() : ($this->disk()->getConfig()['bucket'] ?? null);
            if (! is_string($bucket) || $bucket === '') {
                throw new VersionedObjectTransportException('s3_versioned_read_unavailable');
            }
        } catch (VersionedObjectTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new VersionedObjectTransportException('s3_versioned_read_unavailable', 0, $exception);
        }
        $arguments = ['Bucket' => $bucket, 'Key' => $path];
        try {
            $head = $client->headObject([...$arguments, '@http' => $this->s3HttpOptions()]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        }
        $contentLength = $head['ContentLength'] ?? null;
        $contentLengthBytes = is_numeric($contentLength) ? (int) $contentLength : -1;
        if (
            $contentLengthBytes < 0
            || $contentLengthBytes > $streamLimitBytes
        ) {
            throw new VersionedObjectIntegrityException('s3_object_size_invalid');
        }
        $metadata = is_array($head['Metadata'] ?? null) ? $head['Metadata'] : [];
        if ($reportObject) {
            $metadata = $this->normalizeReportMetadata($metadata);
            $this->assertClosedReportMetadata($metadata, $organizationId);
        }
        try {
            $object = $client->getObject([...$arguments, '@http' => $this->s3HttpOptions()]);
        } catch (AwsException $exception) {
            throw $this->versionedAwsException($exception);
        }
        $stream = $object['Body'] ?? null;
        if (! is_object($stream) || ! method_exists($stream, 'read') || ! method_exists($stream, 'eof')) {
            throw new VersionedObjectIntegrityException('s3_object_stream_invalid');
        }
        $body = '';
        $readBytes = 0;
        $hash = hash_init('sha256');
        while (! $stream->eof()) {
            $remaining = $streamLimitBytes + 1 - $readBytes;
            if ($remaining <= 0) {
                throw new VersionedObjectIntegrityException('s3_object_size_invalid');
            }
            $chunk = $stream->read(min(8192, $remaining));
            if (! is_string($chunk)) {
                throw new VersionedObjectIntegrityException('s3_object_stream_invalid');
            }
            $readBytes += strlen($chunk);
            hash_update($hash, $chunk);
            if ($includeBody) {
                $body .= $chunk;
            }
        }
        if ($readBytes !== $contentLengthBytes) {
            throw new VersionedObjectIntegrityException('s3_object_size_mismatch');
        }

        $description = ['path' => $path, 'body' => $body, 'size' => $readBytes,
            'sha256' => hash_final($hash),
            'etag' => is_string($head['ETag'] ?? null) ? trim($head['ETag'], '"') : null,
            'content_type' => is_string($head['ContentType'] ?? null) ? $head['ContentType'] : 'application/octet-stream'];
        if ($reportObject) {
            $description['metadata'] = $metadata;
        }

        return $description;
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

    /** @return array{size:int,etag:?string} */
    public function describeHead(string $path): array
    {
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('s3_versioned_read_unavailable');
        }
        $head = $this->s3Client()->headObject([
            'Bucket' => $bucket,
            'Key' => $path,
            '@http' => $this->s3HttpOptions(),
        ]);
        $size = $head['ContentLength'] ?? null;
        $etag = is_string($head['ETag'] ?? null) ? trim($head['ETag'], '"') : null;
        if (! is_numeric($size) || (int) $size < 1) {
            throw new \RuntimeException('s3_object_head_invalid');
        }

        return ['size' => (int) $size, 'etag' => $etag];
    }

    /** @return array{path:string,size:int,etag:?string} */
    public function duplicateEstimateGenerationObject(string $sourcePath, string $destinationPath): array
    {
        $pattern = '#^org-([1-9][0-9]*)/estimate-generation/sessions/[1-9][0-9]*/documents/[A-Za-z0-9._-]+$#D';
        if (
            preg_match($pattern, $sourcePath, $sourceMatch) !== 1
            || preg_match($pattern, $destinationPath, $destinationMatch) !== 1
            || $sourceMatch[1] !== $destinationMatch[1]
            || hash_equals($sourcePath, $destinationPath)
        ) {
            throw new \InvalidArgumentException('estimate_generation_copy_path_invalid');
        }

        $disk = $this->disk();
        if ($disk->copy($sourcePath, $destinationPath) !== true) {
            throw new VersionedObjectTransportException('s3_object_copy_failed');
        }

        try {
            $head = $this->describeHead($destinationPath);
            $this->tagEstimateGenerationObject($destinationPath, true);

            return ['path' => $destinationPath, ...$head];
        } catch (\Throwable $exception) {
            try {
                $disk->delete($destinationPath);
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    public function removeImmutable(string $path): void
    {
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('s3_versioned_delete_unavailable');
        }
        $arguments = ['Bucket' => $bucket, 'Key' => $path];
        $this->s3Client()->deleteObject([...$arguments, '@http' => $this->s3HttpOptions()]);
    }

    protected function reportDisk(): FilesystemAdapter|Filesystem
    {
        return $this->disk();
    }

    protected function reportS3Client(): \Aws\S3\S3ClientInterface
    {
        return $this->s3Client();
    }

    protected function reportBucket(): string
    {
        $bucket = $this->reportDisk()->getConfig()['bucket'] ?? null;
        if (! is_string($bucket) || trim($bucket) === '') {
            throw new VersionedObjectTransportException('s3_multipart_unavailable');
        }

        return $bucket;
    }

    protected function s3Client(): \Aws\S3\S3ClientInterface
    {
        $disk = $this->disk();
        if (! method_exists($disk, 'getClient')) {
            throw new \RuntimeException('s3_conditional_put_unavailable');
        }
        $client = $disk->getClient();
        if (! $client instanceof \Aws\S3\S3ClientInterface) {
            throw new \RuntimeException('s3_conditional_put_unavailable');
        }

        return $client;
    }

    private function assertOrganizationPath(string $path): int
    {
        if (
            preg_match('#^org-([1-9][0-9]*)/[^\\\\\x00-\x1F\x7F]+$#D', $path, $matches) !== 1
            || strlen($path) > 1024
            || str_contains($path, '://')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1
        ) {
            throw new \InvalidArgumentException('organization_storage_path_invalid');
        }

        return (int) $matches[1];
    }

    /** @return array{0: int, 1: string} */
    private function describeCurrentContents(mixed $contents): array
    {
        if (is_string($contents)) {
            if ($contents === '') {
                throw new \InvalidArgumentException('storage_object_empty');
            }

            return [strlen($contents), hash('sha256', $contents)];
        }

        if (! is_resource($contents) || get_resource_type($contents) !== 'stream') {
            throw new \InvalidArgumentException('storage_object_contents_invalid');
        }

        $metadata = stream_get_meta_data($contents);
        $position = ftell($contents);
        if (($metadata['seekable'] ?? false) !== true || $position === false) {
            throw new \InvalidArgumentException('storage_object_contents_invalid');
        }

        $hash = hash_init('sha256');
        $sizeBytes = 0;
        while (! feof($contents)) {
            $chunk = fread($contents, 1024 * 1024);
            if ($chunk === false) {
                throw new \InvalidArgumentException('storage_object_contents_invalid');
            }
            $sizeBytes += strlen($chunk);
            hash_update($hash, $chunk);
        }
        if (fseek($contents, $position) !== 0 || $sizeBytes < 1) {
            throw new \InvalidArgumentException('storage_object_contents_invalid');
        }

        return [$sizeBytes, hash_final($hash)];
    }

    /** @param resource $stream @return array{0: int, 1: string} */
    private function hashReadStream($stream): array
    {
        $hash = hash_init('sha256');
        $sizeBytes = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new VersionedObjectIntegrityException('s3_multipart_current_object_read_failed');
            }
            $sizeBytes += strlen($chunk);
            hash_update($hash, $chunk);
        }

        if ($sizeBytes < 1) {
            throw new VersionedObjectIntegrityException('s3_multipart_current_object_read_failed');
        }

        return [$sizeBytes, hash_final($hash)];
    }

    private function deleteInvalidCurrentObject(string $key): void
    {
        try {
            $this->deleteCurrent($key);
        } catch (\Throwable $exception) {
            Log::error('Failed to remove invalid completed multipart object', [
                'key' => $key,
                'exception' => $exception::class,
            ]);
        }
    }

    private function assertSafeStorageString(string $value, int $maxLength, string $error): void
    {
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException($error);
        }
    }

    private function assertStorageMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key) !== 1
                || ! is_string($value)
                || $value === ''
                || strlen($value) > 2048
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new \InvalidArgumentException('multipart_upload_invalid');
            }
        }
    }

    private function assertMultipartParts(MultipartUpload $upload, array $orderedParts): int
    {
        if (! array_is_list($orderedParts) || $orderedParts === []) {
            throw new \InvalidArgumentException('multipart_parts_invalid');
        }

        $sizeBytes = 0;
        $lastIndex = count($orderedParts) - 1;
        foreach ($orderedParts as $index => $part) {
            if (
                ! $part instanceof MultipartPart
                || ! hash_equals($upload->organizationPath, $part->organizationPath)
                || ! hash_equals($upload->uploadId, $part->uploadId)
                || $part->number !== $index + 1
                || $part->sizeBytes > $upload->partSizeBytes
                || ($index !== $lastIndex && $part->sizeBytes !== $upload->partSizeBytes)
            ) {
                throw new \InvalidArgumentException('multipart_parts_invalid');
            }

            $sizeBytes += $part->sizeBytes;
        }

        return $sizeBytes;
    }

    private function assertMultipartConditions(array $conditions): string
    {
        $keys = array_keys($conditions);
        sort($keys, SORT_STRING);
        if (
            $keys !== ['ApplicationChecksumSHA256', 'IfNoneMatch']
            || ($conditions['IfNoneMatch'] ?? null) !== '*'
            || ! is_string($conditions['ApplicationChecksumSHA256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $conditions['ApplicationChecksumSHA256']) !== 1
        ) {
            throw new \InvalidArgumentException('multipart_conditions_invalid');
        }

        return $conditions['ApplicationChecksumSHA256'];
    }

    private function normalizeReportMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new VersionedObjectIntegrityException('s3_report_metadata_invalid');
            }
            $normalizedKey = strtolower($key);
            if (array_key_exists($normalizedKey, $normalized)) {
                throw new VersionedObjectIntegrityException('s3_report_metadata_invalid');
            }
            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    private function assertClosedReportMetadata(array $metadata, int $organizationId): void
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        $expected = self::REPORT_METADATA_KEYS;
        sort($expected, SORT_STRING);

        if (
            $keys !== $expected
            || ($metadata['organization_id'] ?? null) !== (string) $organizationId
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $metadata['export_id'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['export_hash'] ?? '') !== 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $metadata['run_id'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['result_hash'] ?? '') !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $metadata['snapshot_id'] ?? '') !== 1
            || ! in_array($metadata['snapshot_classification'] ?? null, ['operational', 'official'], true)
            || ! in_array($metadata['data_classification'] ?? null, ['standard', 'sensitive'], true)
        ) {
            throw new VersionedObjectIntegrityException('s3_report_metadata_invalid');
        }

        foreach (['contract_version', 'formula_version', 'source_schema_version', 'renderer_version'] as $key) {
            if (
                ! is_string($metadata[$key] ?? null)
                || $metadata[$key] === ''
                || strlen($metadata[$key]) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $metadata[$key]) === 1
            ) {
                throw new VersionedObjectIntegrityException('s3_report_metadata_invalid');
            }
        }
    }

    private function tagEstimateGenerationObject(
        string $path,
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

        try {
            $client->putObjectTagging([
                'Bucket' => $bucket,
                'Key' => $path,
                '@http' => $this->s3HttpOptions(),
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
                        '@http' => $this->s3HttpOptions(),
                    ]);
                } catch (\Throwable) {
                }
            }

            throw new \RuntimeException('s3_object_tagging_failed', 0, $exception);
        }
    }

    private function s3HttpOptions(): array
    {
        return [
            'connect_timeout' => self::S3_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::S3_REQUEST_TIMEOUT_SECONDS,
        ];
    }

    private function safeStorageFailureCode(\Throwable $exception): string
    {
        return in_array($exception->getMessage(), [
            's3_conditional_put_unavailable',
            's3_object_head_invalid',
            's3_object_tagging_failed',
            's3_object_tagging_unavailable',
        ], true)
            ? $exception->getMessage()
            : 's3_upload_failed';
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
                    'failure_code' => $this->safeStorageFailureCode($e),
                    'exception_message' => $privacyMode ? 'redacted' : $e->getMessage(),
                    'aws_error_code' => $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : null,
                    'trace' => $privacyMode ? 'redacted' : $e->getTraceAsString(),
                ], 'error');

                Log::error('[FileService] S3 put() exception', [
                    'path' => $logStoragePath,
                    'error' => $privacyMode ? 'redacted' : $e->getMessage(),
                    'exception_class' => get_class($e),
                    'failure_code' => $this->safeStorageFailureCode($e),
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
