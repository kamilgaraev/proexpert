<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use Aws\Exception\AwsException;
use Closure;
use HashContext;
use InvalidArgumentException;
use Throwable;

final class S3ReportArtifactStream implements ReportArtifactStream
{
    private const METADATA_KEYS = [
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

    private MultipartUpload $upload;

    private string $buffer = '';

    private HashContext $hash;

    private array $parts = [];

    private int $nextPartNumber = 1;

    private int $sizeBytes = 0;

    private bool $aborted = false;

    private bool $closed = false;

    private bool $completionAccepted = false;

    private bool $verified = false;

    private ?StoredFile $stored = null;

    public function __construct(
        private readonly FileService $files,
        string $organizationPath,
        string $mime,
        int $partSizeBytes,
        private readonly array $metadata,
        private readonly ?Closure $cancellationProbe = null,
        private readonly ?ReportExportStore $exportStore = null,
        private readonly ?ReportExecutionContext $executionContext = null,
    ) {
        self::assertMetadata($metadata, $organizationPath);
        if (
            ($exportStore === null) !== ($executionContext === null)
            || ($executionContext !== null
                && $executionContext->scope->organizationId !== (int) $metadata['organization_id'])
        ) {
            throw new InvalidArgumentException('report_artifact_race_resolver_invalid');
        }

        $this->hash = hash_init('sha256');
        try {
            $this->upload = $files->startMultipart(
                $organizationPath,
                $mime,
                $partSizeBytes,
                $metadata,
            );
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }

    public function write(string $bytes): void
    {
        $this->assertOpen();
        if ($bytes === '') {
            return;
        }

        try {
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $available = $this->upload->partSizeBytes - strlen($this->buffer);
                $chunkLength = min($available, $length - $offset);
                $chunk = substr($bytes, $offset, $chunkLength);
                $this->buffer .= $chunk;
                hash_update($this->hash, $chunk);
                $this->sizeBytes += $chunkLength;
                $offset += $chunkLength;

                if (strlen($this->buffer) === $this->upload->partSizeBytes) {
                    $this->uploadBuffer();
                }
            }
        } catch (Throwable $exception) {
            $this->abortAfterFailure($exception);
        }
    }

    public function cancellationRequested(): bool
    {
        if ($this->cancellationProbe === null || $this->closed) {
            return false;
        }

        try {
            $requested = ($this->cancellationProbe)();
        } catch (Throwable $exception) {
            $this->abortAfterFailure($exception);
        }

        if ($requested === true) {
            $this->abort();

            return true;
        }

        return false;
    }

    public function finish(): StoredFile
    {
        if ($this->verified && $this->stored instanceof StoredFile) {
            return $this->stored;
        }
        $this->assertOpen();

        if ($this->sizeBytes === 0) {
            $exception = new InvalidArgumentException('report_artifact_empty');
            try {
                $this->abort();
            } catch (ReportContractException $abortFailure) {
                throw $abortFailure;
            }

            throw $exception;
        }

        try {
            if ($this->buffer !== '') {
                $this->uploadBuffer();
            }

            $checksumSha256 = hash_final($this->hash);
            $conditions = [
                'IfNoneMatch' => '*',
                'ApplicationChecksumSHA256' => $checksumSha256,
                'MpuObjectSize' => $this->sizeBytes,
            ];

            try {
                $completed = $this->files->completeMultipart(
                    $this->upload,
                    $this->parts,
                    $conditions,
                );
            } catch (Throwable $exception) {
                if ($this->isConditionalConflict($exception)) {
                    $this->abort();

                    return $this->reuseRaceWinner($checksumSha256, $exception);
                }

                $this->abortAfterFailure($exception);
            }

            $this->completionAccepted = true;
            $this->closed = true;
            $headed = $this->files->headVersion($completed->path, $completed->versionId);
            $metadata = $this->exactVersionMetadata($headed);
            if (
                ! self::sameFile($completed, $headed)
                || ! hash_equals($this->upload->organizationPath, $headed->path)
                || ! hash_equals($checksumSha256, $headed->checksum->value)
                || $headed->sizeBytes !== $this->sizeBytes
                || ! hash_equals($this->upload->mime, $headed->mime)
                || ! self::sameMetadata($this->metadata, $metadata)
            ) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
            }

            $this->verified = true;
            $this->stored = $headed;

            return $headed;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! $this->completionAccepted) {
                $this->abortAfterFailure($exception);
            }

            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }

    public function abort(): void
    {
        if ($this->aborted || $this->completionAccepted || $this->verified) {
            return;
        }

        try {
            $this->files->abortMultipart($this->upload);
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }

        $this->aborted = true;
        $this->closed = true;
    }

    public function __destruct()
    {
        try {
            $this->abort();
        } catch (Throwable) {
        }
    }

    private function uploadBuffer(): void
    {
        $bytes = $this->buffer;
        $this->buffer = '';
        $checksum = hash('sha256', $bytes);
        $this->parts[] = $this->files->uploadPart(
            $this->upload,
            $this->nextPartNumber,
            $bytes,
            $checksum,
        );
        ++$this->nextPartNumber;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('report_artifact_stream_closed');
        }
    }

    private function abortAfterFailure(Throwable $exception): never
    {
        try {
            $this->abort();
        } catch (ReportContractException) {
        }

        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            previous: $exception,
        );
    }

    private function reuseRaceWinner(string $checksumSha256, Throwable $conflict): StoredFile
    {
        if ($this->exportStore === null || $this->executionContext === null) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $conflict,
            );
        }

        try {
            $winner = $this->exportStore->get(
                $this->executionContext,
                $this->metadata['export_id'],
            );
            if (
                $winner->status !== ReportExportStatus::READY
                || ! hash_equals($this->metadata['export_hash'], $winner->exportHash->value)
                || $winner->artifactPath === null
                || $winner->versionId === null
                || $winner->etag === null
                || $winner->checksum === null
                || $winner->sizeBytes === null
                || ! hash_equals($this->upload->organizationPath, $winner->artifactPath)
                || ! hash_equals($checksumSha256, $winner->checksum->value)
                || $this->sizeBytes !== $winner->sizeBytes
            ) {
                throw new InvalidArgumentException('report_artifact_race_winner_invalid');
            }

            $headed = $this->files->headVersion($winner->artifactPath, $winner->versionId);
            $metadata = $this->exactVersionMetadata($headed);
            if (
                ! hash_equals($winner->artifactPath, $headed->path)
                || ! hash_equals($winner->versionId, $headed->versionId)
                || ! hash_equals($winner->etag, $headed->etag)
                || ! hash_equals($winner->checksum->value, $headed->checksum->value)
                || $winner->sizeBytes !== $headed->sizeBytes
                || ! hash_equals($this->upload->mime, $headed->mime)
                || ! self::sameMetadata($this->metadata, $metadata)
            ) {
                throw new InvalidArgumentException('report_artifact_race_winner_invalid');
            }

            $this->verified = true;
            $this->stored = $headed;

            return $headed;
        } catch (Throwable $exception) {
            if ($exception instanceof ReportContractException) {
                throw $exception;
            }

            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }

    private function isConditionalConflict(Throwable $exception): bool
    {
        do {
            if (
                $exception instanceof AwsException
                && in_array($exception->getStatusCode(), [409, 412], true)
            ) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }

    private static function sameFile(StoredFile $left, StoredFile $right): bool
    {
        return hash_equals($left->path, $right->path)
            && hash_equals($left->versionId, $right->versionId)
            && hash_equals($left->etag, $right->etag)
            && $left->sizeBytes === $right->sizeBytes
            && hash_equals($left->checksum->value, $right->checksum->value)
            && hash_equals($left->mime, $right->mime);
    }

    private function exactVersionMetadata(StoredFile $file): array
    {
        $description = $this->files->describeVersion(
            $file->path,
            $file->versionId,
            $file->sizeBytes,
            false,
        );
        if (
            ($description['path'] ?? null) !== $file->path
            || ($description['version_id'] ?? null) !== $file->versionId
            || ($description['etag'] ?? null) !== $file->etag
            || ($description['size'] ?? null) !== $file->sizeBytes
            || ($description['sha256'] ?? null) !== $file->checksum->value
            || ($description['content_type'] ?? null) !== $file->mime
            || ! is_array($description['metadata'] ?? null)
        ) {
            throw new InvalidArgumentException('report_artifact_version_description_invalid');
        }

        return $description['metadata'];
    }

    private static function sameMetadata(array $expected, array $actual): bool
    {
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        return $expected === $actual;
    }

    private static function assertMetadata(array $metadata, string $organizationPath): void
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        $expected = self::METADATA_KEYS;
        sort($expected, SORT_STRING);
        preg_match('#^org-([1-9][0-9]*)/#D', $organizationPath, $path);

        if (
            $keys !== $expected
            || ! isset($path[1])
            || ($metadata['organization_id'] ?? null) !== $path[1]
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $metadata['export_id'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['export_hash'] ?? '') !== 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $metadata['run_id'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['result_hash'] ?? '') !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $metadata['snapshot_id'] ?? '') !== 1
            || ! in_array($metadata['snapshot_classification'] ?? null, ['operational', 'official'], true)
            || ! in_array($metadata['data_classification'] ?? null, ['standard', 'sensitive'], true)
        ) {
            throw new InvalidArgumentException('report_artifact_metadata_invalid');
        }

        foreach (['contract_version', 'formula_version', 'source_schema_version', 'renderer_version'] as $key) {
            if (
                ! is_string($metadata[$key] ?? null)
                || $metadata[$key] === ''
                || strlen($metadata[$key]) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $metadata[$key]) === 1
            ) {
                throw new InvalidArgumentException('report_artifact_metadata_invalid');
            }
        }
    }
}
