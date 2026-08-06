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
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\OrganizationStoragePath;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReportCompletedArtifactReconciliationResult
{
    public function __construct(
        public int $scanned,
        public int $sealed,
        public int $skipped,
    ) {
        if (
            min($scanned, $sealed, $skipped) < 0
            || $sealed > 1
            || $scanned !== $sealed + $skipped
        ) {
            throw new InvalidArgumentException(
                'report_completed_artifact_reconciliation_result_invalid',
            );
        }
    }
}

final readonly class ReconcileCompletedReportArtifacts
{
    public function __construct(
        private ReportArtifactVersionInventory $inventory,
        private ReportCompletedArtifactRecoveryStore $recovery,
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private ReportAuthorizationSubjectReader $subjects,
        private CurrentReportExactManyAuthorizer $authorizer,
        private ReportExecutionContextFactory $contextFactory,
        private int $leaseSeconds,
    ) {
        if ($leaseSeconds !== 960) {
            throw new InvalidArgumentException(
                'report_completed_artifact_reconciliation_configuration_invalid',
            );
        }
    }

    public function reconcile(
        ReportExecutionContext $context,
        string $exportId,
        DateTimeImmutable $occurredAt,
    ): ReportCompletedArtifactReconciliationResult {
        $export = $this->exports->get($context, $exportId);
        $source = $this->runs->exportSource($context, $export->runId);
        $published = $this->definitions->published($source->run->reportCode);
        $this->assertIdentity($export, $source, $published);
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
        ) as $artifact) {
            $this->assertArtifact($artifact);
            if (! $this->hasExpectedMetadata($current, $export, $source, $artifact)) {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_INTERNAL_ERROR,
                );
            }
            if ($this->matches($current, $export, $artifact)) {
                $matches[] = $artifact;
            } else {
                if (! hash_equals($artifact['mime'], $this->mime($export->format))) {
                    throw ReportContractException::fromCode(
                        ReportErrorCode::REPORT_INTERNAL_ERROR,
                    );
                }
                $unmatched[] = $artifact;
            }
        }

        if (count($matches) > 1) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }

        if ($matches === [] && $unmatched === []) {
            return new ReportCompletedArtifactReconciliationResult(0, 0, 0);
        }

        $current = $fence->assertCurrent($current);
        $leaseToken = strtolower((string) Str::uuid());
        $claimed = $this->recovery->claimExpiredUpload(
            $current,
            $exportId,
            $leaseToken,
            $occurredAt->modify("+{$this->leaseSeconds} seconds"),
            $occurredAt,
        );
        if (! hash_equals($claimed->id, $exportId)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
        $current = $fence->assertCurrent($current);

        if ($matches === []) {
            return new ReportCompletedArtifactReconciliationResult(
                count($unmatched),
                0,
                count($unmatched),
            );
        }

        $match = $matches[0];
        $artifact = new StoredFile(
            $match['path'],
            $match['etag'],
            $match['size'],
            $match['sha256'],
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

        return new ReportCompletedArtifactReconciliationResult(
            1 + count($unmatched),
            1,
            count($unmatched),
        );
    }

    private function assertIdentity(
        ReportExport $export,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
    ): void {
        if (
            $export->status !== ReportExportStatus::UPLOADING
            || $source->run->status !== ReportRunStatus::READY
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
     * @param  list<string>  $columns
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
            $subject->exportFormat,
            $this->authorizer,
            $this->contextFactory,
        );
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function matches(
        ReportExecutionContext $context,
        ReportExport $export,
        array $artifact,
    ): bool {
        return hash_equals($artifact['path'], OrganizationStoragePath::forActor(
            $context->scope->organizationId,
            'reports',
            "exports/{$export->id}",
            $context->actor->id,
            'artifact',
            $export->format,
        )) && hash_equals($artifact['mime'], $this->mime($export->format));
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function hasExpectedMetadata(
        ReportExecutionContext $context,
        ReportExport $export,
        ReportRunExportSource $source,
        array $artifact,
    ): bool {
        $actualMetadata = $artifact['metadata'];
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
     * @param  array<string, mixed>  $artifact
     */
    private function assertArtifact(array $artifact): void
    {
        $keys = array_keys($artifact);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'created_at',
            'etag',
            'metadata',
            'mime',
            'path',
            'sha256',
            'size',
        ]
            || ! is_string($artifact['path'])
            || ! is_string($artifact['etag'])
            || ! is_int($artifact['size'])
            || $artifact['size'] < 1
            || ! is_string($artifact['sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256']) !== 1
            || ! is_string($artifact['mime'])
            || ! is_array($artifact['metadata'])
            || ! $artifact['created_at'] instanceof DateTimeImmutable
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_INTERNAL_ERROR,
            );
        }
    }
}
