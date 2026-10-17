<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\ReadReportExportFileAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExportFile;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\Services\Storage\FileService;
use Throwable;

final readonly class ReadReportExportFileHandler implements ReadReportExportFileAction
{
    public function __construct(private CreateReportDownloadLinkAction $downloads, private FileService $files) {}

    public function handle(ReportExecutionContext $context, string $exportId): ReportExportFile
    {
        $grant = $this->downloads->handle($context, new CreateReportDownloadLinkData($exportId, 300));
        $format = pathinfo($grant->storageKey, PATHINFO_EXTENSION);
        $mime = match ($format) {
            'csv' => CsvReportExportRenderer::MIME_TYPE, 'xlsx' => XlsxReportExportRenderer::MIME_TYPE,
            'pdf' => PdfReportExportRenderer::MIME_TYPE, default => null,
        };
        if ($mime === null || ! str_starts_with($grant->storageKey, 'org-'.$context->scope->organizationId.'/')) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
        try {
            $stream = $this->files->readCurrent($grant->storageKey);
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED, previous: $exception);
        }

        return new ReportExportFile('report-'.$exportId.'.'.$format, $mime, static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        });
    }
}
