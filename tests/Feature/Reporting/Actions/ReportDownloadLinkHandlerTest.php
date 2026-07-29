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
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
#[Group('ci-only')]
final class ReportDownloadLinkHandlerTest extends TestCase
{
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
            'version-1',
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
        $exports->expects(self::once())
            ->method('withReadyDownload')
            ->willThrowException(
                ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_EXPIRED),
            );
        $files = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createTemporaryLink'])
            ->getMock();
        $files->expects(self::never())->method('createTemporaryLink');
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
        );
        $subjects = $this->createStub(ReportAuthorizationSubjectReader::class);
        $subjects->method('export')->willReturn($subject);
        $definitions = $this->createStub(ReportDefinitionRegistry::class);
        $definitions->method('published')->willReturn(
            new \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition($definition),
        );
        $authorizer = $this->createStub(CurrentReportScopeAuthorizer::class);
        $authorizer->method('authorizeExact')->willReturnCallback(
            static fn (int $actorId, $scope, $target): CurrentReportAuthorization => new CurrentReportAuthorization(
                $context->actor,
                $context->authorization,
                $context->visibility,
                $target,
            ),
        );
        $handler = new CreateReportDownloadLinkHandler(
            $exports,
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
