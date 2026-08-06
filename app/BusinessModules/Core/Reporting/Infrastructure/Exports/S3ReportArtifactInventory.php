<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\Services\Storage\FileService;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

final readonly class S3ReportArtifactInventory implements ReportArtifactVersionInventory
{
    private const METADATA_KEYS = [
        'contract_version',
        'data_classification',
        'export_hash',
        'export_id',
        'formula_version',
        'organization_id',
        'renderer_version',
        'result_hash',
        'run_id',
        'snapshot_classification',
        'snapshot_id',
        'source_schema_version',
    ];

    public function __construct(
        private S3ClientInterface $client,
        private FileService $files,
        private string $bucket,
    ) {
        if (
            trim($bucket) === ''
            || strlen($bucket) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $bucket) === 1
        ) {
            throw new InvalidArgumentException('report_artifact_inventory_bucket_invalid');
        }
    }

    public function forExport(int $organizationId, string $exportId): iterable
    {
        if (
            $organizationId < 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $exportId) !== 1
        ) {
            throw new InvalidArgumentException('report_artifact_inventory_scope_invalid');
        }

        $prefix = "org-{$organizationId}/reports/exports/{$exportId}/";
        try {
            $pages = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);
            foreach ($pages as $page) {
                $objects = is_array($page['Contents'] ?? null)
                    ? $page['Contents']
                    : [];
                foreach ($objects as $object) {
                    if (! is_array($object)) {
                        throw new InvalidArgumentException(
                            'report_artifact_inventory_entry_invalid',
                        );
                    }

                    $path = $this->string($object['Key'] ?? null);
                    $createdAt = $this->instant($object['LastModified'] ?? null);
                    if (! str_starts_with($path, $prefix)) {
                        throw new InvalidArgumentException(
                            'report_artifact_inventory_entry_invalid',
                        );
                    }

                    $description = $this->files->describeCurrent(
                        $path,
                        -ReportExportLimits::ARTIFACT_MAX_BYTES,
                    );
                    $metadata = $this->metadata($description['metadata'] ?? null);
                    $entry = [
                        'path' => $path,
                        'etag' => $this->string($description['etag'] ?? null),
                        'size' => $this->integer($description['size'] ?? null),
                        'sha256' => $this->hash($description['sha256'] ?? null),
                        'mime' => $this->string(
                            $description['content_type'] ?? null,
                        ),
                        'metadata' => $metadata,
                        'created_at' => $createdAt,
                    ];
                    if (
                        ! hash_equals(
                            $entry['path'],
                            $this->string($description['path'] ?? null),
                        )
                        || $entry['size'] < 1
                        || $entry['size'] > ReportExportLimits::ARTIFACT_MAX_BYTES
                    ) {
                        throw new InvalidArgumentException(
                            'report_artifact_inventory_entry_invalid',
                        );
                    }

                    yield $entry;
                }
            }
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }

    private function metadata(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('report_artifact_inventory_metadata_invalid');
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== self::METADATA_KEYS) {
            throw new InvalidArgumentException('report_artifact_inventory_metadata_invalid');
        }
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException(
                    'report_artifact_inventory_metadata_invalid',
                );
            }
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('report_artifact_inventory_entry_invalid');
        }

        return trim($value, " \t\n\r\0\x0B\"");
    }

    private function hash(mixed $value): string
    {
        $hash = $this->string($value);
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('report_artifact_inventory_entry_invalid');
        }

        return $hash;
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException('report_artifact_inventory_entry_invalid');
        }

        return $value;
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        throw new InvalidArgumentException('report_artifact_inventory_entry_invalid');
    }
}
