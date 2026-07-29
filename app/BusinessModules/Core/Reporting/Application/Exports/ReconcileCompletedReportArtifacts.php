<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionWatchdogSummary;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class ReconcileCompletedReportArtifacts
{
    private const LEASE_SECONDS = 960;

    private const DELETE_GRACE_SECONDS = 3600;

    public function __construct(
        private ReportArtifactVersionInventory $inventory,
        private ReportCompletedArtifactRecoveryStore $recovery,
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private ReportAuthorizationSubjectReader $subjects,
        private CurrentReportExactManyAuthorizer $authorizer,
        private ReportExecutionContextFactory $contextFactory,
        private FileService $files,
    ) {}

    public function reconcile(
        ReportExecutionContext $context,
        string $exportId,
        DateTimeImmutable $occurredAt,
    ): ReportExecutionWatchdogSummary {
        $export = $this->exports->get($context, $exportId);
        $source = $this->runs->exportSource($context, $export->runId);
        $published = $this->definitions->published($source->run->reportCode);
        $this->assertIdentity($export, $source, $published, $occurredAt);
        $columns = $published->definition->validatedSelectedColumnIds(
            $export->columns,
        );
        $fence = $this->fence(
            $context,
            $export,
            $source,
            $columns,
        );
        $current = $fence->assertCurrent($context);

        $matches = [];
        $unmatched = [];
        foreach ($this->inventory->forExport(
            $context->scope->organizationId,
            $exportId,
        ) as $version) {
            $this->assertVersion($version);
            if (! $this->hasExpectedMetadata($current, $export, $source, $version)) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_INTERNAL_ERROR,
                );
            }
            if ($this->matches($current, $export, $version)) {
                $matches[] = $version;
            } else {
                if (! hash_equals($version['mime'], $this->mime($export->format))) {
                    throw ReportContractException::fromCode(
                        ReportErrorCode::REPORT_INTERNAL_ERROR,
                    );
                }
                $unmatched[] = $version;
            }
        }

        if (count($matches) > 1) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }

        if ($matches === [] && $unmatched === []) {
            return new ReportExecutionWatchdogSummary(0, 0, 0, 0);
        }

        $current = $fence->assertCurrent($current);
        $leaseToken = strtolower((string) Str::uuid());
        $claimed = $this->recovery->claimExpiredUpload(
            $current,
            $exportId,
            $leaseToken,
            $occurredAt->modify('+'.self::LEASE_SECONDS.' seconds'),
            $occurredAt,
        );
        if (! hash_equals($claimed->id, $exportId)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
        $current = $fence->assertCurrent($current);

        if ($matches === []) {
            [$deleted, $skipped] = $this->deleteUnmatched(
                $unmatched,
                $occurredAt,
            );

            return new ReportExecutionWatchdogSummary(
                count($unmatched),
                0,
                $skipped,
                $deleted,
            );
        }

        [$deleted, $skipped] = $this->deleteUnmatched(
            $unmatched,
            $occurredAt,
        );
        $match = $matches[0];
        $artifact = new StoredFile(
            $match['path'],
            $match['version_id'],
            $match['etag'],
            $match['size'],
            new Sha256Hash($match['sha256']),
            $match['mime'],
        );
        $this->exports->sealReady(
            $current,
            $exportId,
            $leaseToken,
            $artifact,
            $source->result->metadata->rowCount,
            $occurredAt,
        );

        return new ReportExecutionWatchdogSummary(
            1 + count($unmatched),
            1,
            $skipped,
            $deleted,
        );
    }

    private function assertIdentity(
        ReportExport $export,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        DateTimeImmutable $occurredAt,
    ): void {
        if (
            $export->status !== ReportExportStatus::UPLOADING
            || $export->expiresAt <= $occurredAt
            || $source->run->status !== ReportRunStatus::READY
            || $source->run->expiresAt <= $occurredAt
            || ! hash_equals($export->runId, $source->run->id)
            || ! hash_equals(
                $published->definitionHash->value,
                $source->run->definitionHash->value,
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
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_NOT_READY,
            );
        }
    }

    /**
     * @param list<string> $columns
     */
    private function fence(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
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
                $source->run->definitionHash->value,
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
        if ($source->outputClassification->requiresSensitiveForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if ($source->outputClassification->requiresAuditForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }

        return new ReportAuthorizationFence(
            $subject,
            $operations,
            $this->authorizer,
            $this->contextFactory,
        );
    }

    /**
     * @param array<string, mixed> $version
     */
    private function matches(
        ReportExecutionContext $context,
        ReportExport $export,
        array $version,
    ): bool {
        return hash_equals(
            $version['path'],
            "org-{$context->scope->organizationId}/reports/exports/"
                ."{$export->id}/artifact.{$export->format}",
        ) && hash_equals($version['mime'], $this->mime($export->format));
    }

    /**
     * @param array<string, mixed> $version
     */
    private function hasExpectedMetadata(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
        array $version,
    ): bool {
        $actualMetadata = $version['metadata'];
        $expectedMetadata = [
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
        ksort($actualMetadata, SORT_STRING);
        ksort($expectedMetadata, SORT_STRING);

        return $actualMetadata === $expectedMetadata;
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED,
            ),
        };
    }

    /**
     * @param array<string, mixed> $version
     */
    private function assertVersion(array $version): void
    {
        $keys = array_keys($version);
        sort($keys, SORT_STRING);
        if ($keys !== [
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
            || ! is_string($version['version_id'])
            || ! is_string($version['etag'])
            || ! is_int($version['size'])
            || $version['size'] < 1
            || ! is_string($version['sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $version['sha256']) !== 1
            || ! is_string($version['mime'])
            || ! is_array($version['metadata'])
            || ! $version['created_at'] instanceof DateTimeImmutable
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $versions
     * @return array{int, int}
     */
    private function deleteUnmatched(
        array $versions,
        DateTimeImmutable $occurredAt,
    ): array {
        $deleted = 0;
        $skipped = 0;
        foreach ($versions as $version) {
            if (
                $version['created_at']
                <= $occurredAt->modify('-'.self::DELETE_GRACE_SECONDS.' seconds')
            ) {
                $this->files->deleteVersion(
                    $version['path'],
                    $version['version_id'],
                );
                ++$deleted;
            } else {
                ++$skipped;
            }
        }

        return [$deleted, $skipped];
    }
}
