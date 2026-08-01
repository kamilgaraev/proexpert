<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportReadyDownloadStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\Services\Storage\FileService;
use Throwable;

final readonly class CreateReportDownloadLinkHandler implements CreateReportDownloadLinkAction
{
    public function __construct(
        private ReportExportStore $exports,
        private ReportReadyDownloadStore $downloads,
        private ReportDefinitionRegistry $definitions,
        private CurrentReportScopeAuthorizer $authorizer,
        private FileService $files,
        private ReportExecutionClock $clock,
        private ReportExecutionContextFactory $contexts,
        private ReportAuthorizationSubjectReader $subjects,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        CreateReportDownloadLinkData $data,
    ): ReportDownloadLink {
        $subject = $this->subjects->export($data->exportId);
        ReportAuthorizationFence::assertExactScope($context, $subject);
        $export = $this->exports->get($context, $data->exportId);
        $definition = $this->definitions->published($subject->definition->code)->definition;
        if (! hash_equals($definition->definitionHash->value, $subject->definition->definitionHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $operations = [ReportOperation::DOWNLOAD];
        if ($definition->outputClassification->requiresSensitiveForColumns($export->columns)) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if ($definition->outputClassification->requiresAuditForColumns($export->columns)) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }
        $fence = new ReportAuthorizationFence(
            $subject,
            $operations,
            $subject->exportFormat,
            $this->authorizer,
            $this->contexts,
        );
        $fence->assertCurrent($context);

        try {
            return $this->downloads->withReadyDownload(
                $context,
                $data->exportId,
                $data->ttlSeconds,
                $fence,
                function (ReportExport $lockedExport, int $boundedTtlSeconds) use ($context): ReportDownloadLink {
                    if ($lockedExport->artifactPath === null
                        || $lockedExport->versionId === null
                        || ! str_starts_with(
                            $lockedExport->artifactPath,
                            'org-'.$context->scope->organizationId.'/',
                        )) {
                        throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
                    }

                    $temporary = $this->files->createTemporaryLink(
                        $lockedExport->artifactPath,
                        $lockedExport->versionId,
                        $boundedTtlSeconds,
                    );
                    if (! hash_equals($lockedExport->versionId, $temporary->versionId)) {
                        throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
                    }

                    return new ReportDownloadLink(
                        $temporary->url,
                        $temporary->versionId,
                        $this->clock->now(),
                        $temporary->expiresAt,
                    );
                },
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }
}
