<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportDownloadLinkHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportReadyDownloadStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionService;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinReportHistoricalReadCompatibility;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportDownloadLinkHandlerTest extends TestCase
{
    #[DataProvider('downloadCases')]
    public function test_created_artifact_key_is_the_same_current_key_signed_for_download(bool $legacy, bool $revoked): void
    {
        $baseContext = (new ReportExecutionContextBuilder)->build();
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor(
                7,
                $baseContext->actor->status,
                $baseContext->actor->permissionSlugs,
            ))
            ->build();
        $export = new ReportExport(
            '01J00000000000000000000001',
            '01J00000000000000000000000',
            ReportExportStatus::READY,
            new Sha256Hash(str_repeat('d', 64)),
            'csv',
            ['name'],
            new ReportWindowSort('name', ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('Europe/Moscow'),
            'org-1/reports/exports/01J00000000000000000000001/user-7/artifact.csv',
            'etag-1',
            new Sha256Hash(str_repeat('e', 64)),
            128,
            1,
            new DateTimeImmutable('2026-07-29T08:00:00+00:00'),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            new DateTimeImmutable('2026-07-29T09:00:00+00:00'),
            null,
            'reused',
            null,
        );
        $writer = (new ReflectionClass(ReportExportExecutionService::class))->newInstanceWithoutConstructor();
        $createdKey = (new ReflectionMethod(ReportExportExecutionService::class, 'artifactPath'))
            ->invoke($writer, $context, $export);
        self::assertSame($export->artifactPath, $createdKey);
        $exports = $this->createStub(ReportExportStore::class);
        $exports->method('get')->willReturn($export);
        $downloads = $this->createMock(ReportReadyDownloadStore::class);
        $downloads->expects($revoked ? self::never() : self::once())->method('withReadyDownload')->willReturnCallback(
            static fn ($lockedContext, $exportId, $ttl, $fence, $presign) => $presign($export, 120),
        );
        $files = new class extends FileService
        {
            /** @var list<array{string, int}> */
            public array $requests = [];

            public function __construct() {}

            public function temporaryDownloadUrl(string $key, int $ttlSeconds): string
            {
                $this->requests[] = [$key, $ttlSeconds];

                return 'https://storage.example.test/'.rawurlencode($key).'?ttl='.$ttlSeconds;
            }
        };
        $clock = $this->createStub(ReportExecutionClock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-07-29T08:31:00+00:00'));
        $builtin = new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract);
        $document = $builtin->document();
        $document['capabilities'] = ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false];
        $definition = $legacy ? (new ReportDefinitionFactory)->fromManifest($document) : (new ReportDefinitionBuilder)->payload();
        $snapshot = new ReportSnapshotRef(
            'report',
            'snapshot',
            $context->scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('c', 64)),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            $export->id,
            $definition,
            $context->scope,
            $snapshot,
            $export->runId,
            $export->checksum,
            $export->exportHash,
            $export->format,
        );
        $subjects = $this->createStub(ReportAuthorizationSubjectReader::class);
        $subjects->method('export')->willReturn($subject);
        $definitions = $this->createStub(ReportDefinitionRegistry::class);
        $definitions->method('published')->willReturn(
            $legacy ? $builtin->definition() : new \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition($definition),
        );
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizer->expects(self::once())->method('authorizeExactMany')->willReturnCallback(
            static function (int $actorId, $scope, array $targets) use ($context, $definition, $revoked): array {
                self::assertSame($definition->definitionHash->value, $targets[0]->definition->definitionHash->value);
                if ($revoked) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
                }

                return array_map(
                    static fn ($target): CurrentReportAuthorization => new CurrentReportAuthorization(
                        $context->actor,
                        $context->authorization,
                        $context->visibility,
                        $target,
                    ),
                    $targets,
                );
            },
        );

        if ($revoked) {
            $this->expectException(ReportContractException::class);
        }
        $link = (new CreateReportDownloadLinkHandler(
            $exports,
            $downloads,
            $definitions,
            $authorizer,
            $files,
            $clock,
            new ReportExecutionContextFactory,
            $subjects,
            new BuiltinReportHistoricalReadCompatibility($builtin),
        ))->handle($context, new CreateReportDownloadLinkData($export->id, 300));

        self::assertSame([
            ['org-1/reports/exports/01J00000000000000000000001/user-7/artifact.csv', 120],
        ], $files->requests);
        self::assertSame(
            'org-1/reports/exports/01J00000000000000000000001/user-7/artifact.csv',
            $link->storageKey,
        );
    }

    public static function downloadCases(): array
    {
        return [[false, false], [true, false], [true, true]];
    }

    public function test_expired_export_returns_exact_gone_error_before_parent_or_url_generation(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $export = new ReportExport(
            '01J00000000000000000000001',
            '01J00000000000000000000000',
            ReportExportStatus::READY,
            new Sha256Hash(str_repeat('d', 64)),
            'csv',
            ['name'],
            new ReportWindowSort('name', ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('Europe/Moscow'),
            'org-1/reports/export.csv',
            'etag-1',
            new Sha256Hash(str_repeat('e', 64)),
            128,
            1,
            new DateTimeImmutable('2026-07-29T08:00:00+00:00'),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            new DateTimeImmutable('2026-07-29T09:00:00+00:00'),
            null,
            'reused',
            null,
        );
        $exports = $this->createMock(ReportExportStore::class);
        $exports->method('get')->willReturn($export);
        $downloads = $this->createMock(ReportReadyDownloadStore::class);
        $downloads->expects(self::once())
            ->method('withReadyDownload')
            ->willThrowException(
                ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_EXPIRED),
            );
        $files = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['temporaryDownloadUrl'])
            ->getMock();
        $files->expects(self::never())->method('temporaryDownloadUrl');
        $clock = $this->createStub(ReportExecutionClock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-07-29T10:00:00+00:00'));
        $definition = (new ReportDefinitionBuilder)->payload();
        $snapshot = new ReportSnapshotRef(
            'report',
            'snapshot',
            $context->scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('c', 64)),
            new DateTimeImmutable('2026-07-29T08:30:00+00:00'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            $export->id,
            $definition,
            $context->scope,
            $snapshot,
            $export->runId,
            $export->checksum,
            $export->exportHash,
            $export->format,
        );
        $subjects = $this->createStub(ReportAuthorizationSubjectReader::class);
        $subjects->method('export')->willReturn($subject);
        $definitions = $this->createStub(ReportDefinitionRegistry::class);
        $definitions->method('published')->willReturn(
            new \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition($definition),
        );
        $authorizer = $this->createStub(CurrentReportScopeAuthorizer::class);
        $authorizer->method('authorizeExactMany')->willReturnCallback(
            static fn (int $actorId, $scope, array $targets): array => array_map(
                static fn ($target): CurrentReportAuthorization => new CurrentReportAuthorization(
                    $context->actor,
                    $context->authorization,
                    $context->visibility,
                    $target,
                ),
                $targets,
            ),
        );
        $handler = new CreateReportDownloadLinkHandler(
            $exports,
            $downloads,
            $definitions,
            $authorizer,
            $files,
            $clock,
            new ReportExecutionContextFactory,
            $subjects,
        );

        try {
            $handler->handle($context, new CreateReportDownloadLinkData($export->id, 300));
            self::fail('Expected expired export to reject download link.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_EXPORT_EXPIRED, $exception->errorCode);
        }
    }
}
