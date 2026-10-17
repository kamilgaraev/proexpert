<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\ReadReportExportFileHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReadReportExportFileHandlerTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_file_stream_reuses_download_grant_and_closes_private_storage_stream(string $format, string $mime): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $id = '01M530JYMNTDT83G8G5BCHVZ5W';
        $key = 'org-'.$context->scope->organizationId.'/reports/exports/'.$id.'/user-1/artifact.'.$format;
        $grant = $this->createMock(CreateReportDownloadLinkAction::class);
        $grant->expects(self::once())->method('handle')->with($context, self::callback(static fn ($data): bool => $data->exportId === $id && $data->ttlSeconds === 300))
            ->willReturn(new ReportDownloadLink('https://storage.example.test/private', $key, new DateTimeImmutable('2026-10-17T01:00:00Z'), new DateTimeImmutable('2026-10-17T01:05:00Z')));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'private report bytes');
        rewind($stream);
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('readCurrent')->with($key)->willReturn($stream);
        $file = (new ReadReportExportFileHandler($grant, $files))->handle($context, $id);
        self::assertSame('report-'.$id.'.'.$format, $file->filename);
        self::assertSame($mime, $file->mimeType);
        ob_start();
        ($file->writeToOutput)();
        self::assertSame('private report bytes', ob_get_clean());
        self::assertFalse(is_resource($stream));
    }

    #[DataProvider('denials')]
    public function test_download_denial_is_preserved_before_any_storage_read(ReportErrorCode $code): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $grant = $this->createMock(CreateReportDownloadLinkAction::class);
        $exception = ReportContractException::fromCode($code);
        $grant->method('handle')->willThrowException($exception);
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('readCurrent');
        try {
            (new ReadReportExportFileHandler($grant, $files))->handle($context, '01M530JYMNTDT83G8G5BCHVZ5W');
            self::fail('Expected fresh download authorization denial');
        } catch (ReportContractException $actual) {
            self::assertSame($exception, $actual);
        }
    }

    public static function formats(): array
    {
        return [['csv', 'text/csv; charset=UTF-8'], ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], ['pdf', 'application/pdf']];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_private_grant_key_fails_before_storage_read(string $key): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $grant = $this->createMock(CreateReportDownloadLinkAction::class);
        $grant->method('handle')->willReturn(new ReportDownloadLink(
            'https://storage.example.test/private', $key,
            new DateTimeImmutable('2026-10-17T01:00:00Z'), new DateTimeImmutable('2026-10-17T01:05:00Z'),
        ));
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('readCurrent');

        $this->expectExceptionObject(ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR));
        (new ReadReportExportFileHandler($grant, $files))->handle($context, '01M530JYMNTDT83G8G5BCHVZ5W');
    }

    public function test_storage_failure_is_reported_before_streaming_any_bytes(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $grant = $this->createMock(CreateReportDownloadLinkAction::class);
        $grant->method('handle')->willReturn(new ReportDownloadLink(
            'https://storage.example.test/private', 'org-'.$context->scope->organizationId.'/report.csv',
            new DateTimeImmutable('2026-10-17T01:00:00Z'), new DateTimeImmutable('2026-10-17T01:05:00Z'),
        ));
        $failure = new RuntimeException('storage unavailable');
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('readCurrent')->willThrowException($failure);

        try {
            (new ReadReportExportFileHandler($grant, $files))->handle($context, '01M530JYMNTDT83G8G5BCHVZ5W');
            self::fail('Expected storage dependency failure');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public static function invalidKeys(): array
    {
        return [['org-999/report.csv'], ['org-1/report.html']];
    }

    public static function denials(): array
    {
        return [[ReportErrorCode::REPORT_SCOPE_FORBIDDEN], [ReportErrorCode::REPORT_NOT_FOUND], [ReportErrorCode::REPORT_EXPORT_NOT_READY], [ReportErrorCode::REPORT_EXPORT_EXPIRED]];
    }
}
