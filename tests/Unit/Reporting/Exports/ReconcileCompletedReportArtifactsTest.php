<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReconcileCompletedReportArtifacts;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportCompletedArtifactReconciliationResult;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\S3ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Storage\FileService;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReconcileCompletedReportArtifactsTest extends TestCase
{
    private const EXPORT_ID = '01J00000000000000000000001';

    private const RUN_ID = '01J00000000000000000000000';

    public function test_exact_version_is_sealed_before_old_orphan_is_deleted_with_full_reauthorization(): void
    {
        [$context, $export, $source, $published, $subject] = $this->fixture();
        $exact = $this->version($context->scope->organizationId, $export, $source);
        $orphan = $exact;
        $orphan['path'] = 'org-1/reports/exports/'.self::EXPORT_ID.'/old-part';
        $orphan['version_id'] = 'old-version';
        $orphan['created_at'] = new DateTimeImmutable('2025-12-31T22:00:00Z');
        $mutations = [];
        $recovery = $this->createMock(ReportCompletedArtifactRecoveryStore::class);
        $recovery->expects(self::once())
            ->method('claimExpiredUpload')
            ->willReturnCallback(static function () use (&$mutations, $export) {
                $mutations[] = 'claim';

                return $export;
            });
        $exports = $this->createMock(ReportExportStore::class);
        $exports->expects(self::once())->method('get')->willReturn($export);
        $exports->expects(self::once())
            ->method('sealReady')
            ->willReturnCallback(function () use (&$mutations) {
                $mutations[] = 'seal';

                return $this->readyExport();
            });
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())
            ->method('deleteVersion')
            ->with($orphan['path'], 'old-version')
            ->willReturnCallback(static function () use (&$mutations): void {
                $mutations[] = 'delete';
            });
        $authorizer = $this->authorizer($context, 3);

        $result = $this->service(
            [$exact, $orphan],
            $recovery,
            $exports,
            $files,
            $source,
            $published,
            $subject,
            $authorizer,
        )->reconcile(
            $context,
            self::EXPORT_ID,
            new DateTimeImmutable('2026-01-01T00:02:00Z'),
        );

        self::assertInstanceOf(ReportCompletedArtifactReconciliationResult::class, $result);
        self::assertSame(2, $result->scanned);
        self::assertSame(1, $result->sealed);
        self::assertSame(0, $result->skipped);
        self::assertSame(1, $result->deleted);
        self::assertSame(['claim', 'delete', 'seal'], $mutations);
    }

    public function test_metadata_drift_fails_before_claim_seal_or_delete(): void
    {
        [$context, $export, $source, $published, $subject] = $this->fixture();
        $version = $this->version(1, $export, $source);
        $version['metadata']['renderer_version'] = 'drift';
        $recovery = $this->createMock(ReportCompletedArtifactRecoveryStore::class);
        $recovery->expects(self::never())->method('claimExpiredUpload');
        $exports = $this->createMock(ReportExportStore::class);
        $exports->expects(self::once())->method('get')->willReturn($export);
        $exports->expects(self::never())->method('sealReady');
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('deleteVersion');

        try {
            $this->service(
                [$version],
                $recovery,
                $exports,
                $files,
                $source,
                $published,
                $subject,
                $this->authorizer($context, 1),
            )->reconcile(
                $context,
                self::EXPORT_ID,
                new DateTimeImmutable('2026-01-01T00:02:00Z'),
            );
            self::fail('Metadata drift was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
    }

    public function test_recovery_cas_loser_performs_no_s3_mutation_or_ready_transition(): void
    {
        [$context, $export, $source, $published, $subject] = $this->fixture();
        $orphan = $this->version(1, $export, $source);
        $orphan['path'] = 'org-1/reports/exports/'.self::EXPORT_ID.'/old-part';
        $recovery = $this->createMock(ReportCompletedArtifactRecoveryStore::class);
        $recovery->expects(self::once())
            ->method('claimExpiredUpload')
            ->willThrowException(ReportContractException::fromCode(
                ReportErrorCode::REPORT_EXPORT_NOT_READY,
            ));
        $exports = $this->createMock(ReportExportStore::class);
        $exports->expects(self::once())->method('get')->willReturn($export);
        $exports->expects(self::never())->method('sealReady');
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('deleteVersion');

        $this->expectException(ReportContractException::class);
        $this->service(
            [$orphan],
            $recovery,
            $exports,
            $files,
            $source,
            $published,
            $subject,
            $this->authorizer($context, 2),
        )->reconcile(
            $context,
            self::EXPORT_ID,
            new DateTimeImmutable('2026-01-01T00:02:00Z'),
        );
    }

    public function test_multiple_exact_versions_fail_before_recovery_claim(): void
    {
        [$context, $export, $source, $published, $subject] = $this->fixture();
        $first = $this->version(1, $export, $source);
        $second = $first;
        $second['version_id'] = 'version-2';
        $recovery = $this->createMock(ReportCompletedArtifactRecoveryStore::class);
        $recovery->expects(self::never())->method('claimExpiredUpload');
        $exports = $this->createMock(ReportExportStore::class);
        $exports->method('get')->willReturn($export);

        $this->expectException(ReportContractException::class);
        $this->service(
            [$first, $second],
            $recovery,
            $exports,
            $this->createMock(FileService::class),
            $source,
            $published,
            $subject,
            $this->authorizer($context, 1),
        )->reconcile(
            $context,
            self::EXPORT_ID,
            new DateTimeImmutable('2026-01-01T00:02:00Z'),
        );
    }

    public function test_s3_inventory_paginates_exact_prefix_and_returns_closed_versions(): void
    {
        $client = $this->createMock(S3ClientInterface::class);
        $client->expects(self::once())
            ->method('getPaginator')
            ->with('ListObjectVersions', [
                'Bucket' => 'reports',
                'Prefix' => 'org-1/reports/exports/'.self::EXPORT_ID.'/',
            ])
            ->willReturn(new \ArrayIterator([[
                'Versions' => [[
                    'Key' => 'org-1/reports/exports/'.self::EXPORT_ID.'/artifact.csv',
                    'VersionId' => 'version-1',
                    'LastModified' => new DateTimeImmutable('2026-01-01T00:01:00Z'),
                ]],
            ]]));
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())
            ->method('describeVersion')
            ->with(
                'org-1/reports/exports/'.self::EXPORT_ID.'/artifact.csv',
                'version-1',
                self::lessThan(0),
            )
            ->willReturn([
                'path' => 'org-1/reports/exports/'.self::EXPORT_ID.'/artifact.csv',
                'version_id' => 'version-1',
                'etag' => 'etag-1',
                'size' => 12,
                'sha256' => str_repeat('e', 64),
                'content_type' => 'text/csv; charset=UTF-8',
                'metadata' => [
                    'contract_version' => '1',
                    'data_classification' => 'standard',
                    'export_hash' => str_repeat('d', 64),
                    'export_id' => self::EXPORT_ID,
                    'formula_version' => '1',
                    'organization_id' => '1',
                    'renderer_version' => '1',
                    'result_hash' => str_repeat('f', 64),
                    'run_id' => self::RUN_ID,
                    'snapshot_classification' => 'operational',
                    'snapshot_id' => 'snapshot-1',
                    'source_schema_version' => '1',
                ],
            ]);

        $versions = iterator_to_array(
            (new S3ReportArtifactVersionInventory(
                $client,
                $files,
                'reports',
            ))->forExport(1, self::EXPORT_ID),
            false,
        );

        self::assertCount(1, $versions);
        self::assertSame('version-1', $versions[0]['version_id']);
        self::assertSame(str_repeat('e', 64), $versions[0]['sha256']);
    }

    private function service(
        array $versions,
        ReportCompletedArtifactRecoveryStore $recovery,
        ReportExportStore $exports,
        FileService $files,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        ReportAuthorizationSubject $subject,
        CurrentReportExactManyAuthorizer $authorizer,
    ): ReconcileCompletedReportArtifacts {
        $inventory = new class($versions) implements ReportArtifactVersionInventory
        {
            public function __construct(private array $versions) {}

            public function forExport(int $organizationId, string $exportId): iterable
            {
                yield from $this->versions;
            }
        };
        $runs = $this->createMock(ReportRunStore::class);
        $runs->method('exportSource')->willReturn($source);
        $definitions = $this->createMock(ReportDefinitionRegistry::class);
        $definitions->method('published')->willReturn($published);
        $subjects = $this->createMock(ReportAuthorizationSubjectReader::class);
        $subjects->method('export')->willReturn($subject);

        return new ReconcileCompletedReportArtifacts(
            $inventory,
            $recovery,
            $exports,
            $runs,
            $definitions,
            $subjects,
            $authorizer,
            new ReportExecutionContextFactory,
            $files,
        );
    }

    private function authorizer(
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext $context,
        int $calls,
    ): CurrentReportExactManyAuthorizer {
        $authorizer = $this->createMock(CurrentReportExactManyAuthorizer::class);
        $authorizer->expects(self::exactly($calls))
            ->method('authorizeExactMany')
            ->willReturnCallback(static function (
                int $actorId,
                ReportScope $scope,
                array $targets,
            ) use ($context): array {
                return array_map(
                    static fn ($target): CurrentReportAuthorization => new CurrentReportAuthorization(
                        $context->actor,
                        $context->authorization,
                        $context->visibility,
                        $target,
                    ),
                    $targets,
                );
            });

        return $authorizer;
    }

    private function fixture(): array
    {
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
        $base = (new ReportExecutionContextBuilder)->build();
        $context = (new ReportExecutionContextBuilder)
            ->actor($base->actor)
            ->scope($scope)
            ->visibility($base->visibility)
            ->authorization($base->authorization)
            ->build();
        $published = (new ReportDefinitionBuilder)
            ->code('report')
            ->published();
        $source = $this->source($scope, $published);
        $export = (new ReportExportBuilder)
            ->id(self::EXPORT_ID)
            ->runId(self::RUN_ID)
            ->status(ReportExportStatus::UPLOADING)
            ->exportHash(new Sha256Hash(str_repeat('d', 64)))
            ->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->updatedAt(new DateTimeImmutable('2026-01-01T00:01:00Z'))
            ->expiresAt(new DateTimeImmutable('2026-01-01T01:00:00Z'))
            ->queued();
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $published->definition,
            $scope,
            $source->snapshot,
            self::RUN_ID,
            null,
        );

        return [$context, $export, $source, $published, $subject];
    }

    private function source(
        ReportScope $scope,
        PublishedReportDefinition $published,
    ): ReportRunExportSource {
        $definition = $published->definition;
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2025-12-31T23:58:00Z'),
            'ru',
        );
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $generatedAt = new DateTimeImmutable('2025-12-31T23:59:00Z');
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot-1',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $provenance = new ReportProvenance(
            'system',
            [new ReportSourceRef('system', 'table', 'snapshot_1', 'v1', 'wm_1', 0, $sourceHash)],
            $sourceHash,
            null,
        );
        $metadata = new ReportResultMetadata($snapshot, 0, $generatedAt, null);
        $result = new ReportResult(
            $metadata,
            [],
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            [['id' => 'name']],
            [],
        );
        $run = (new ReportRunBuilder)
            ->id(self::RUN_ID)
            ->reportCode($definition->code)
            ->definitionHash($definition->definitionHash)
            ->contractVersion($definition->contractVersion)
            ->formulaVersion($definition->formulaVersion)
            ->sourceSchemaVersion($definition->sourceSchemaVersion)
            ->rendererVersion($definition->rendererVersion)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->rowCount(0)
            ->resultMetadata($metadata)
            ->freshness(ReportFreshnessStatus::FRESH)
            ->quality($quality)
            ->provenance($provenance)
            ->createdAt(new DateTimeImmutable('2025-12-31T23:58:00Z'))
            ->updatedAt($generatedAt)
            ->readyAt($generatedAt)
            ->expiresAt(new DateTimeImmutable('2026-01-01T01:00:00Z'))
            ->ready();
        $projection = (new ReflectionClass(ReportRunExportSource::class))
            ->getMethod('resultProjection')
            ->invoke(null, $result);

        return new ReportRunExportSource(
            $run,
            $query,
            $result,
            new Sha256Hash(hash('sha256', CanonicalJson::encode($projection))),
            $snapshot,
            ReportDataClassification::STANDARD,
            new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                [],
                [],
                false,
                false,
                false,
            ),
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
        );
    }

    private function version(
        int $organizationId,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport $export,
        ReportRunExportSource $source,
    ): array {
        return [
            'path' => "org-{$organizationId}/reports/exports/{$export->id}/artifact.csv",
            'version_id' => 'version-1',
            'etag' => 'etag-1',
            'size' => 12,
            'sha256' => str_repeat('e', 64),
            'mime' => 'text/csv; charset=UTF-8',
            'metadata' => [
                'organization_id' => (string) $organizationId,
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
            ],
            'created_at' => new DateTimeImmutable('2026-01-01T00:01:00Z'),
        ];
    }

    private function readyExport(): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport
    {
        return (new ReportExportBuilder)
            ->id(self::EXPORT_ID)
            ->runId(self::RUN_ID)
            ->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->updatedAt(new DateTimeImmutable('2026-01-01T00:02:00Z'))
            ->readyAt(new DateTimeImmutable('2026-01-01T00:02:00Z'))
            ->expiresAt(new DateTimeImmutable('2026-01-01T01:00:00Z'))
            ->rowCount(0)
            ->sizeBytes(12)
            ->ready();
    }
}
