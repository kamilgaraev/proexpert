<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\S3ReportArtifactStream;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7\Response;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Throwable;

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
        self::assertSame(
            hash('sha256', $fullPart.'tail'),
            $files->conditions['ApplicationChecksumSHA256'],
        );
        self::assertSame(
            [['org-7/reports/exports/01J00000000000000000000001/artifact.csv', 'version-1', -(self::PART_SIZE + 4)]],
            $files->descriptions,
        );
        self::assertEquals($files->headed, $stored);
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
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
            self::assertSame('upload_failed', $exception->getPrevious()?->getMessage());
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
        foreach (['extra', 'missing'] as $case) {
            $files = new RecordingMultipartFileService();
            $metadata = $this->metadata();
            if ($case === 'extra') {
                $metadata['actor_id'] = '42';
            } else {
                unset($metadata['renderer_version']);
            }

            try {
                new S3ReportArtifactStream(
                    $files,
                    'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
                    'text/csv',
                    self::PART_SIZE,
                    $metadata,
                );
                self::fail('Invalid metadata was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_artifact_metadata_invalid', $exception->getMessage());
            }
            self::assertSame([], $files->starts);
        }
    }

    public function test_normal_completion_compares_every_exact_metadata_member(): void
    {
        $files = new RecordingMultipartFileService();
        $stream = $this->stream($files);
        $files->describedMetadata = $this->metadata();
        $files->describedMetadata['renderer_version'] = 'different';
        $stream->write(str_repeat('a', self::PART_SIZE).'tail');

        try {
            $stream->finish();
            self::fail('Metadata drift was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        self::assertSame(0, $files->abortCount);
    }

    #[DataProvider('conditionalStatusProvider')]
    public function test_conditional_race_reuses_only_exact_ready_winner_and_aborts_loser_once(int $status): void
    {
        $files = new RecordingMultipartFileService();
        $files->completionFailure = $this->conditionalConflict($status);
        $bytes = 'body';
        $checksum = hash('sha256', $bytes);
        $files->headed = new StoredFile(
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'winner-version',
            'winner-etag',
            strlen($bytes),
            new Sha256Hash($checksum),
            'text/csv',
        );
        $files->describedMetadata = $this->metadata();
        $winner = (new ReportExportBuilder())
            ->id($this->metadata()['export_id'])
            ->runId($this->metadata()['run_id'])
            ->exportHash(new Sha256Hash($this->metadata()['export_hash']))
            ->artifactPath($files->headed->path)
            ->versionId($files->headed->versionId)
            ->etag($files->headed->etag)
            ->checksum($files->headed->checksum)
            ->sizeBytes($files->headed->sizeBytes)
            ->ready();
        $store = $this->createStub(ReportExportStore::class);
        $store->method('get')->willReturn($winner);
        $stream = new S3ReportArtifactStream(
            $files,
            $files->headed->path,
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
            null,
            $store,
            $this->executionContext(),
        );
        $stream->write($bytes);

        self::assertEquals($files->headed, $stream->finish());
        self::assertSame(1, $files->abortCount);
        self::assertSame([[$files->headed->path, 'winner-version', -4]], $files->descriptions);
    }

    public static function conditionalStatusProvider(): iterable
    {
        yield '409 conflict' => [409];
        yield '412 precondition failed' => [412];
    }

    public function test_conditional_race_metadata_mismatch_fails_closed_after_one_loser_abort(): void
    {
        $files = new RecordingMultipartFileService();
        $files->completionFailure = $this->conditionalConflict();
        $bytes = 'body';
        $checksum = hash('sha256', $bytes);
        $files->headed = new StoredFile(
            'org-7/reports/exports/01J00000000000000000000001/artifact.csv',
            'winner-version',
            'winner-etag',
            strlen($bytes),
            new Sha256Hash($checksum),
            'text/csv',
        );
        $files->describedMetadata = $this->metadata();
        $files->describedMetadata['snapshot_id'] = 'other-snapshot';
        $winner = (new ReportExportBuilder())
            ->id($this->metadata()['export_id'])
            ->runId($this->metadata()['run_id'])
            ->exportHash(new Sha256Hash($this->metadata()['export_hash']))
            ->artifactPath($files->headed->path)
            ->versionId($files->headed->versionId)
            ->etag($files->headed->etag)
            ->checksum($files->headed->checksum)
            ->sizeBytes($files->headed->sizeBytes)
            ->ready();
        $store = $this->createStub(ReportExportStore::class);
        $store->method('get')->willReturn($winner);
        $stream = new S3ReportArtifactStream(
            $files,
            $files->headed->path,
            'text/csv',
            self::PART_SIZE,
            $this->metadata(),
            null,
            $store,
            $this->executionContext(),
        );
        $stream->write($bytes);

        try {
            $stream->finish();
            self::fail('Race winner metadata mismatch was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }
        self::assertSame(1, $files->abortCount);
    }

    public function test_failed_abort_is_retryable_and_never_exposes_raw_storage_failure(): void
    {
        $files = new RecordingMultipartFileService();
        $files->abortFailuresRemaining = 1;
        $stream = $this->stream($files);

        try {
            $stream->abort();
            self::fail('Abort transport failure was swallowed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        $stream->abort();
        self::assertSame(2, $files->abortCount);
    }

    public function test_complete_and_head_failures_are_safe_and_respect_completion_boundary(): void
    {
        $completeFiles = new RecordingMultipartFileService();
        $completeFiles->completionFailure = new RuntimeException('complete_provider_detail');
        $completeStream = $this->stream($completeFiles);
        $completeStream->write('body');
        try {
            $completeStream->finish();
            self::fail('Complete failure was swallowed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
            self::assertSame('complete_provider_detail', $exception->getPrevious()?->getMessage());
        }
        self::assertSame(1, $completeFiles->abortCount);

        $headFiles = new RecordingMultipartFileService();
        $headFiles->headFailure = new RuntimeException('head_provider_detail');
        $headStream = $this->stream($headFiles);
        $headStream->write('body');
        try {
            $headStream->finish();
            self::fail('Head failure was swallowed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
            self::assertSame('head_provider_detail', $exception->getPrevious()?->getMessage());
        }
        self::assertSame(0, $headFiles->abortCount);
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

    private function conditionalConflict(int $status = 412): AwsException
    {
        return new AwsException(
            'provider detail',
            $this->createStub(CommandInterface::class),
            ['response' => new Response($status)],
        );
    }

    private function executionContext(): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope(7, [7], [], [], $timezone);
        $authorization = new AuthorizationDecisionContext(
            'http',
            7,
            [7],
            [],
            [],
            $timezone,
            'report-test',
            null,
        );

        return (new ReportExecutionContextBuilder())
            ->scope($scope)
            ->authorization($authorization)
            ->build();
    }
}

final class RecordingMultipartFileService extends FileService
{
    public array $starts = [];

    public array $uploads = [];

    public array $completions = [];

    public array $completedParts = [];

    public array $conditions = [];

    public array $descriptions = [];

    public int $abortCount = 0;

    public int $deleteCount = 0;

    public ?int $failUploadNumber = null;

    public ?Throwable $completionFailure = null;

    public ?Throwable $headFailure = null;

    public int $abortFailuresRemaining = 0;

    public array $describedMetadata = [];

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
        if ($this->describedMetadata === []) {
            $this->describedMetadata = $metadata;
        }
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
        if ($this->completionFailure instanceof Throwable) {
            throw $this->completionFailure;
        }

        $this->completions[] = $upload->uploadId;
        $this->completedParts = $orderedParts;
        $this->conditions = $conditions;
        $checksum = $conditions['ApplicationChecksumSHA256'];
        $sizeBytes = array_sum(array_map(
            static fn (MultipartPart $part): int => $part->sizeBytes,
            $orderedParts,
        ));

        return new StoredFile(
            $upload->organizationPath,
            'version-1',
            'artifact-etag',
            $sizeBytes,
            new Sha256Hash($checksum),
            $upload->mime,
        );
    }

    public function abortMultipart(MultipartUpload $upload): void
    {
        ++$this->abortCount;
        if ($this->abortFailuresRemaining > 0) {
            --$this->abortFailuresRemaining;
            throw new RuntimeException('abort_failed');
        }
    }

    public function describeVersion(
        string $path,
        ?string $versionId,
        int $maxBytes = 64_000_000,
    ): array {
        if ($this->headFailure instanceof Throwable) {
            throw $this->headFailure;
        }
        $this->descriptions[] = [$path, $versionId, $maxBytes];

        return [
            'path' => $path,
            'body' => '',
            'size' => $this->headed->sizeBytes,
            'sha256' => $this->headed->checksum->value,
            'etag' => $this->headed->etag,
            'version_id' => $this->headed->versionId,
            'content_type' => $this->headed->mime,
            'metadata' => $this->describedMetadata,
        ];
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
