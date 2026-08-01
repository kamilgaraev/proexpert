<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\S3ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class ReportExportExecutionService
{
    private const MULTIPART_PART_SIZE = 5_242_880;

    public function __construct(
        private ReportExportAttemptLifecycleStore $attempts,
        private ReportExportExecutionContextRehydrator $contexts,
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingMap $bindings,
        private ReportRowChunkReader $rows,
        private ReportExportRendererRegistry $renderers,
        private FileService $files,
        private ReportArtifactVersionInventory $inventory,
        private ReportExecutionClock $clock,
        private ReportExecutionTelemetry $telemetry,
        private ReportAuthorizationSubjectReader $subjects,
        private CurrentReportExactManyAuthorizer $authorizer,
        private ReportExecutionContextFactory $contextFactory,
        private ReportExecutionRuntimeConfiguration $runtime,
        private int $chunkSize = 1000,
    ) {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new \InvalidArgumentException('report_export_chunk_size_invalid');
        }
    }

    public function execute(string $exportId, string $leaseToken): void
    {
        $this->assertIdentity($exportId, $leaseToken);
        $claimedAt = $this->clock->now();
        if (! $this->attempts->claimOrRenew(
            $exportId,
            $leaseToken,
            $claimedAt->modify("+{$this->runtime->executionLeaseSeconds} seconds"),
            $claimedAt,
        )) {
            return;
        }
        $startedAt = hrtime(true);

        $stream = null;
        $completed = false;
        $reportCode = null;
        $format = null;
        $export = null;
        $source = null;

        try {
            $context = $this->contexts->forExport($exportId);
            $export = $this->exports->get($context, $exportId);
            $format = $export->format;
            $this->assertExecutable($export, $claimedAt);

            $source = $this->runs->exportSource($context, $export->runId);
            $reportCode = $source->run->reportCode;
            $this->assertSourceIdentity($export, $source, $claimedAt);

            $published = $this->definitions->published($reportCode);
            $binding = $this->bindings->get($reportCode);
            $this->assertBindingIdentity($published, $source, $binding);
            Log::info('report_export_execution_started', [
                'export_id' => $exportId,
                'run_id' => $source->run->id,
                'report_code' => $reportCode,
                'format' => $format,
                'export_hash' => $export->exportHash->value,
                'definition_hash' => $source->run->definitionHash->value,
                'result_hash' => $source->resultHash->value,
            ]);

            $data = new CreateReportExportData(
                $export->format,
                $export->columns,
                $export->sort,
                $export->locale,
                $export->timezone,
            );
            $columns = $published->definition->validatedSelectedColumnIds(
                $data->columns,
            );
            if ($columns !== $export->columns) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_INTERNAL_ERROR,
                );
            }

            $fence = $this->authorizationFence(
                $context,
                $export,
                $source,
                $published,
                $columns,
            );
            $context = $fence->assertCurrent($context);
            $resumingUpload = $export->status === ReportExportStatus::UPLOADING;
            if ($resumingUpload) {
                $artifact = $this->completedArtifact(
                    $context,
                    $export,
                    $source,
                );
                if ($artifact instanceof StoredFile) {
                    $context = $fence->assertCurrent($context);
                    $ready = $this->exports->sealReady(
                        $context,
                        $exportId,
                        $leaseToken,
                        $artifact,
                        $source->result->metadata->rowCount,
                        $this->clock->now(),
                    );
                    $this->recordReady(
                        $reportCode,
                        $format,
                        $ready,
                        $source->result->metadata->rowCount,
                        $artifact->sizeBytes,
                    );
                    $this->telemetry->exportDuration(
                        $reportCode,
                        $format,
                        ReportExportStatus::READY->value,
                        $this->elapsedSeconds($startedAt),
                    );
                    $this->logReady($ready, $source);

                    return;
                }
            } else {
                $transitionAt = $this->clock->now();
                $this->exports->startRendering(
                    $context,
                    $exportId,
                    $leaseToken,
                    $transitionAt->modify("+{$this->runtime->executionLeaseSeconds} seconds"),
                    $transitionAt,
                );
                $this->telemetry->exportTransition(
                    $reportCode,
                    $format,
                    ReportExportStatus::RUNNING->value,
                );
            }

            $context = $fence->assertCurrent($context);
            $chunks = $this->rows->read(
                $context,
                $source->snapshot,
                $source->query->queryHash,
                $data->sort,
                $this->chunkSize,
                $binding->rowQuery,
            );
            $renderer = $this->renderers->resolve($published, $data);

            $context = $fence->assertCurrent($context);
            $stream = new S3ReportArtifactStream(
                $this->files,
                $this->artifactPath($context, $export),
                $this->mime($format),
                self::MULTIPART_PART_SIZE,
                $this->metadata($context, $export, $source),
                fn (): bool => $this->cancelled($context, $exportId),
                $this->exports,
                $context,
            );

            $rowCount = $renderer->render($source, $data, $chunks, $stream);

            $context = $fence->assertCurrent($context);
            if (! $resumingUpload) {
                $uploadingAt = $this->clock->now();
                $this->exports->startUploading(
                    $context,
                    $exportId,
                    $leaseToken,
                    $uploadingAt->modify("+{$this->runtime->executionLeaseSeconds} seconds"),
                    $uploadingAt,
                );
                $this->telemetry->exportTransition(
                    $reportCode,
                    $format,
                    ReportExportStatus::UPLOADING->value,
                );
            }

            $context = $fence->assertCurrent($context);
            if ($stream->cancellationRequested()) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_EXPORT_NOT_READY,
                );
            }
            $artifact = $stream->finish();
            $completed = true;

            $ready = $this->exports->sealReady(
                $context,
                $exportId,
                $leaseToken,
                $artifact,
                $rowCount,
                $this->clock->now(),
            );
            $this->recordReady(
                $reportCode,
                $format,
                $ready,
                $rowCount,
                $artifact->sizeBytes,
            );
            $this->telemetry->exportDuration(
                $reportCode,
                $format,
                ReportExportStatus::READY->value,
                $this->elapsedSeconds($startedAt),
            );
            $this->logReady($ready, $source);
        } catch (ReportContractException $exception) {
            $this->abortIncomplete($stream, $completed, $reportCode, $format);
            $descriptor = ReportErrorCatalog::descriptor($exception->errorCode);
            if (! $descriptor->retryable) {
                $failed = $this->attempts->failLeased(
                    $exportId,
                    $leaseToken,
                    $exception->errorCode,
                    $this->clock->now(),
                );
                if ($failed && $reportCode !== null && $format !== null) {
                    $this->telemetry->exportTransition(
                        $reportCode,
                        $format,
                        ReportExportStatus::FAILED->value,
                    );
                }
                if ($reportCode !== null && $format !== null) {
                    $this->telemetry->exportDuration(
                        $reportCode,
                        $format,
                        ReportExportStatus::FAILED->value,
                        $this->elapsedSeconds($startedAt),
                    );
                }
                $this->logFailure($exportId, $export, $source, $exception->errorCode, false);

                return;
            }

            $this->telemetry->executionAttempt(
                'export',
                $exception->errorCode->value,
            );
            if ($reportCode !== null && $format !== null) {
                $this->telemetry->exportDuration(
                    $reportCode,
                    $format,
                    ReportExportStatus::RUNNING->value,
                    $this->elapsedSeconds($startedAt),
                );
            }
            $this->logFailure($exportId, $export, $source, $exception->errorCode, true);

            throw $exception;
        } catch (Throwable $exception) {
            $this->abortIncomplete($stream, $completed, $reportCode, $format);
            $this->telemetry->executionAttempt(
                'export',
                ReportErrorCode::REPORT_INTERNAL_ERROR->value,
            );
            if ($reportCode !== null && $format !== null) {
                $this->telemetry->exportDuration(
                    $reportCode,
                    $format,
                    ReportExportStatus::RUNNING->value,
                    $this->elapsedSeconds($startedAt),
                );
            }
            $this->logFailure(
                $exportId,
                $export,
                $source,
                ReportErrorCode::REPORT_INTERNAL_ERROR,
                true,
            );

            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
                previous: $exception,
            );
        }
    }

    private function assertIdentity(string $exportId, string $leaseToken): void
    {
        if (
            preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $exportId) !== 1
            || ! Str::isUuid($leaseToken)
            || $leaseToken !== strtolower($leaseToken)
        ) {
            throw new \InvalidArgumentException('report_export_execution_identity_invalid');
        }
    }

    private function assertExecutable(
        ReportExport $export,
        DateTimeImmutable $occurredAt,
    ): void {
        if (
            $export->status === ReportExportStatus::EXPIRED
            || $export->expiresAt <= $occurredAt
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_EXPIRED,
            );
        }
        if (! in_array($export->status, [
            ReportExportStatus::RUNNING,
            ReportExportStatus::UPLOADING,
        ], true)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_NOT_READY,
            );
        }
    }

    private function assertSourceIdentity(
        ReportExport $export,
        ReportRunExportSource $source,
        DateTimeImmutable $occurredAt,
    ): void {
        if (
            $source->run->status !== ReportRunStatus::READY
            || $source->run->expiresAt <= $occurredAt
            || ! hash_equals($source->run->id, $export->runId)
            || ! hash_equals(
                $source->run->definitionHash->value,
                $source->query->definition->definitionHash->value,
            )
            || ! hash_equals(
                $source->run->queryHash->value,
                $source->query->queryHash->value,
            )
            || $source->run->sourceHash === null
            || ! hash_equals(
                $source->run->sourceHash->value,
                $source->snapshot->sourceHash->value,
            )
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
    }

    private function assertBindingIdentity(
        PublishedReportDefinition $published,
        ReportRunExportSource $source,
        ReportDefinitionBinding $binding,
    ): void {
        if (
            ! hash_equals(
                $published->definitionHash->value,
                $source->run->definitionHash->value,
            )
            || ! hash_equals(
                $published->definitionHash->value,
                $binding->definitionHash->value,
            )
            || ! hash_equals(
                $published->definition->contractVersion,
                $source->contractVersion,
            )
            || ! hash_equals(
                $published->definition->formulaVersion,
                $source->formulaVersion,
            )
            || ! hash_equals(
                $published->definition->sourceSchemaVersion,
                $source->sourceSchemaVersion,
            )
            || ! hash_equals(
                $published->definition->rendererVersion,
                $source->rendererVersion,
            )
            || ! hash_equals(
                $binding->contractVersion,
                $source->contractVersion,
            )
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function authorizationFence(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        array $columns,
    ): ReportAuthorizationFence {
        $subject = $this->subjects->export($export->id);
        ReportAuthorizationFence::assertExactScope($context, $subject);
        if (
            $subject->aggregateKind !== ReportDispatchAggregate::EXPORT
            || ! hash_equals($subject->aggregateId, $export->id)
            || $subject->parentRunId === null
            || ! hash_equals($subject->parentRunId, $source->run->id)
            || $subject->snapshot === null
            || ! hash_equals(
                $subject->definition->definitionHash->value,
                $published->definitionHash->value,
            )
            || ! hash_equals($subject->snapshot->id, $source->snapshot->id)
            || ! hash_equals(
                $subject->snapshot->sourceHash->value,
                $source->snapshot->sourceHash->value,
            )
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_NOT_FOUND,
            );
        }

        $operations = [ReportOperation::EXPORT];
        $classification = $source->outputClassification;
        if ($classification->requiresSensitiveForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if ($classification->requiresAuditForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }

        return new ReportAuthorizationFence(
            $subject,
            $operations,
            $subject->exportFormat,
            $this->authorizer,
            $this->contextFactory,
        );
    }

    private function artifactPath(
        ReportExecutionContext $context,
        ReportExport $export,
    ): string {
        return "org-{$context->scope->organizationId}/reports/exports/"
            ."{$export->id}/artifact.{$export->format}";
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'csv' => CsvReportExportRenderer::MIME_TYPE,
            'xlsx' => XlsxReportExportRenderer::MIME_TYPE,
            'pdf' => PdfReportExportRenderer::MIME_TYPE,
            default => throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED,
            ),
        };
    }

    /**
     * @return array<string, string>
     */
    private function metadata(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
    ): array {
        return [
            'organization_id' => (string) $context->scope->organizationId,
            'export_id' => $export->id,
            'export_hash' => $export->exportHash->value,
            'run_id' => $source->run->id,
            'result_hash' => $source->resultHash->value,
            'snapshot_id' => $source->snapshot->id,
            'snapshot_classification' => $source->snapshot->classification->value,
            'data_classification' => $source->dataClassification->value,
            'contract_version' => $source->contractVersion,
            'formula_version' => $source->formulaVersion,
            'source_schema_version' => $source->sourceSchemaVersion,
            'renderer_version' => $source->rendererVersion,
        ];
    }

    private function completedArtifact(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
    ): ?StoredFile {
        $matches = [];
        foreach ($this->inventory->forExport(
            $context->scope->organizationId,
            $export->id,
        ) as $version) {
            $this->assertCompletedVersion($context, $export, $source, $version);
            $matches[] = $version;
        }
        if (count($matches) > 1) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
        if ($matches === []) {
            return null;
        }

        $match = $matches[0];

        return new StoredFile(
            $match['path'],
            $match['version_id'],
            $match['etag'],
            $match['size'],
            new Sha256Hash($match['sha256']),
            $match['mime'],
        );
    }

    /**
     * @param  array<string, mixed>  $version
     */
    private function assertCompletedVersion(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
        array $version,
    ): void {
        $keys = array_keys($version);
        sort($keys, SORT_STRING);
        $actualMetadata = $version['metadata'] ?? null;
        $expectedMetadata = $this->metadata($context, $export, $source);
        if (is_array($actualMetadata)) {
            ksort($actualMetadata, SORT_STRING);
        }
        ksort($expectedMetadata, SORT_STRING);
        if (
            $keys !== [
                'created_at',
                'etag',
                'metadata',
                'mime',
                'path',
                'sha256',
                'size',
                'version_id',
            ]
            || ! is_string($version['path'])
            || ! hash_equals($version['path'], $this->artifactPath($context, $export))
            || ! is_string($version['version_id'])
            || $version['version_id'] === ''
            || ! is_string($version['etag'])
            || $version['etag'] === ''
            || ! is_int($version['size'])
            || $version['size'] < 1
            || ! is_string($version['sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $version['sha256']) !== 1
            || ! is_string($version['mime'])
            || ! hash_equals($version['mime'], $this->mime($export->format))
            || $actualMetadata !== $expectedMetadata
            || ! $version['created_at'] instanceof DateTimeImmutable
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
    }

    private function recordReady(
        string $reportCode,
        string $format,
        ReportExport $ready,
        int $rowCount,
        int $sizeBytes,
    ): void {
        $this->telemetry->exportTransition(
            $reportCode,
            $format,
            ReportExportStatus::READY->value,
        );
        $this->telemetry->exportArtifact(
            $reportCode,
            $format,
            $ready->rowCount ?? $rowCount,
            $ready->sizeBytes ?? $sizeBytes,
        );
    }

    private function logReady(ReportExport $ready, ReportRunExportSource $source): void
    {
        Log::info('report_export_execution_ready', [
            'export_id' => $ready->id,
            'run_id' => $source->run->id,
            'report_code' => $source->run->reportCode,
            'format' => $ready->format,
            'export_hash' => $ready->exportHash->value,
            'definition_hash' => $source->run->definitionHash->value,
            'result_hash' => $source->resultHash->value,
            'row_count' => $ready->rowCount,
            'size_bytes' => $ready->sizeBytes,
        ]);
    }

    private function logFailure(
        string $exportId,
        ?ReportExport $export,
        ?ReportRunExportSource $source,
        ReportErrorCode $errorCode,
        bool $retryable,
    ): void {
        Log::warning('report_export_execution_failed', [
            'export_id' => $exportId,
            'run_id' => $source?->run->id ?? $export?->runId,
            'report_code' => $source?->run->reportCode,
            'format' => $export?->format,
            'export_hash' => $export?->exportHash->value,
            'definition_hash' => $source?->run->definitionHash->value,
            'result_hash' => $source?->resultHash->value,
            'error_code' => $errorCode->value,
            'retryable' => $retryable,
        ]);
    }

    private function cancelled(
        ReportExecutionContext $context,
        string $exportId,
    ): bool {
        return $this->exports->get($context, $exportId)->status
            === ReportExportStatus::CANCELLED;
    }

    private function abortIncomplete(
        ?S3ReportArtifactStream $stream,
        bool $completed,
        ?string $reportCode,
        ?string $format,
    ): void {
        if (! $stream instanceof S3ReportArtifactStream || $completed) {
            return;
        }

        try {
            $stream->abort();
        } catch (Throwable) {
        }
        if ($reportCode !== null && $format !== null) {
            $this->telemetry->multipartAbort($reportCode, $format);
        }
    }

    private function elapsedSeconds(int $startedAt): float
    {
        return max(0.0, (hrtime(true) - $startedAt) / 1_000_000_000);
    }
}
