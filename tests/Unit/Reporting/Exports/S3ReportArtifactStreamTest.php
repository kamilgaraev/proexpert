<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\S3ReportArtifactStream;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class S3ReportArtifactStreamTest extends TestCase
{
    private const PART_SIZE = 5 * 1024 * 1024;

    public function test_stream_uploads_bounded_parts_then_verifies_the_exact_completed_version(): void
    {
        $files = new RecordingMultipartFileService();
        $stream = new S3ReportArtifactStream(
            $files,
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );
        $fullPart = str_repeat('a', self::PART_SIZE);

        $stream->write($fullPart.'tail');
        $stored = $stream->finish();

        self::assertCount(1, $files->starts);
        self::assertSame([1, 2], array_column($files->uploads, 'number'));
        self::assertSame([self::PART_SIZE, 4], array_column($files->uploads, 'size'));
        self::assertSame([$fullPart, 'tail'], array_column($files->uploads, 'bytes'));
        self::assertSame([1, 2], array_map(
            static fn (MultipartPart $part): int => $part->number,
            $files->completedParts,
        ));
        self::assertSame('*', $files->conditions['IfNoneMatch']);
        self::assertSame(self::PART_SIZE + 4, $files->conditions['MpuObjectSize']);
        self::assertSame(
            hash('sha256', $fullPart.'tail'),
            bin2hex(base64_decode($files->conditions['ChecksumSHA256'], true)),
        );
        self::assertSame(
            [['org-7/reports/exports/01J00000000000000000000001/artifact.csv', 'version-1']],
            $files->heads,
        );
        self::assertSame($files->headed, $stored);
        self::assertSame(0, $files->abortCount);
    }

    public function test_zero_byte_export_is_rejected_and_aborted_once(): void
    {
        $files = new RecordingMultipartFileService();
        $stream = $this->stream($files);

        try {
            $stream->finish();
            self::fail('Zero-byte artifact was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('report_artifact_empty', $exception->getMessage());
        }

        $stream->abort();
        self::assertSame(1, $files->abortCount);
        self::assertSame([], $files->completions);
    }

    public function test_upload_failure_and_renderer_cleanup_abort_only_once(): void
    {
        $files = new RecordingMultipartFileService();
        $files->failUploadNumber = 1;
        $stream = $this->stream($files);

        try {
            $stream->write(str_repeat('x', self::PART_SIZE));
            self::fail('Upload failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('upload_failed', $exception->getMessage());
        }

        $stream->abort();
        $stream->abort();
        self::assertSame(1, $files->abortCount);

        $rendererFiles = new RecordingMultipartFileService();
        $rendererStream = $this->stream($rendererFiles);
        try {
            $rendererStream->write('partial');
            throw new RuntimeException('renderer_failed');
        } catch (RuntimeException) {
            $rendererStream->abort();
            $rendererStream->abort();
        }
        self::assertSame(1, $rendererFiles->abortCount);
    }

    public function test_cancellation_aborts_before_the_renderer_continues(): void
    {
        $files = new RecordingMultipartFileService();
        $stream = new S3ReportArtifactStream(
            $files,
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
            static fn (): bool => true,
        );

        self::assertTrue($stream->cancellationRequested());
        self::assertSame(1, $files->abortCount);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('report_artifact_stream_closed');
        $stream->write('late');
    }

    public function test_post_completion_head_mismatch_never_aborts_or_deletes_the_version(): void
    {
        $files = new RecordingMultipartFileService();
        $files->headed = new StoredFile(
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'different-version',
            'etag',
            4,
            new Sha256Hash(hash('sha256', 'body')),
            'text/csv',
        );
        $stream = $this->stream($files);
        $stream->write('body');

        try {
            $stream->finish();
            self::fail('Wrong headed version was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        self::assertSame(0, $files->abortCount);
        self::assertSame(0, $files->deleteCount);
    }

    public function test_closed_metadata_rejects_forbidden_or_missing_members_before_storage(): void
    {
        $files = new RecordingMultipartFileService();
        $metadata = $this->metadata();
        $metadata['actor_id'] = '42';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_artifact_metadata_invalid');
        try {
            new S3ReportArtifactStream(
                $files,
                'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
                'text/csv',
                self::PART_SIZE,
                $metadata,
            );
        } finally {
            self::assertSame([], $files->starts);
        }
    }

    private function stream(RecordingMultipartFileService $files): S3ReportArtifactStream
    {
        return new S3ReportArtifactStream(
            $files,
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
        );
    }

    private function metadata(): array
    {
        return [
            'organization_id' => '7',
            'export_id' => '01J00000000000000000000001',
            'export_hash' => str_repeat('a', 64),
            'run_id' => '01J00000000000000000000002',
            'result_hash' => str_repeat('b', 64),
            'snapshot_id' => 'snapshot-1',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ];
    }
}

final class RecordingMultipartFileService extends FileService
{
    public array $starts = [];

    public array $uploads = [];

    public array $completions = [];

    public array $completedParts = [];

    public array $conditions = [];

    public array $heads = [];

    public int $abortCount = 0;

    public int $deleteCount = 0;

    public ?int $failUploadNumber = null;

    public StoredFile $headed;

    private MultipartUpload $upload;

    public function __construct()
    {
        $this->headed = new StoredFile(
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'version-1',
            'artifact-etag',
            self::partSize() + 4,
            new Sha256Hash(hash('sha256', str_repeat('a', self::partSize()).'tail')),
            'text/csv',
        );
    }

    public function startMultipart(
        string $organizationPath,
        string $mime,
        int $partSizeBytes,
        array $metadata,
    ): MultipartUpload {
        $this->starts[] = compact('organizationPath', 'mime', 'partSizeBytes', 'metadata');
        $this->upload = new MultipartUpload(
            $organizationPath,
            'upload-1',
            $mime,
            $partSizeBytes,
            $metadata,
        );

        return $this->upload;
    }

    public function uploadPart(
        MultipartUpload $upload,
        int $partNumber,
        string $bytes,
        string $checksumSha256,
    ): MultipartPart {
        if ($this->failUploadNumber === $partNumber) {
            throw new RuntimeException('upload_failed');
        }

        $this->uploads[] = [
            'number' => $partNumber,
            'size' => strlen($bytes),
            'bytes' => $bytes,
            'checksum' => $checksumSha256,
        ];

        return new MultipartPart(
            $upload->organizationPath,
            $upload->uploadId,
            $partNumber,
            'etag-'.$partNumber,
            strlen($bytes),
            $checksumSha256,
        );
    }

    public function completeMultipart(
        MultipartUpload $upload,
        array $orderedParts,
        array $conditions,
    ): StoredFile {
        $this->completions[] = $upload->uploadId;
        $this->completedParts = $orderedParts;
        $this->conditions = $conditions;
        $checksum = bin2hex(base64_decode($conditions['ChecksumSHA256'], true));

        return new StoredFile(
            $upload->organizationPath,
            'version-1',
            'artifact-etag',
            $conditions['MpuObjectSize'],
            new Sha256Hash($checksum),
            $upload->mime,
        );
    }

    public function abortMultipart(MultipartUpload $upload): void
    {
        ++$this->abortCount;
    }

    public function headVersion(string $organizationPath, string $versionId): StoredFile
    {
        $this->heads[] = [$organizationPath, $versionId];

        return $this->headed;
    }

    public function deleteVersion(string $organizationPath, string $versionId): void
    {
        ++$this->deleteCount;
    }

    private static function partSize(): int
    {
        return 5 * 1024 * 1024;
    }
}
