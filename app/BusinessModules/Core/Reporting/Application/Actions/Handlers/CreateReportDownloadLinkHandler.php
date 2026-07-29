<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\Services\Storage\FileService;
use Throwable;

final readonly class CreateReportDownloadLinkHandler implements CreateReportDownloadLinkAction
{
    public function __construct(
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private CurrentReportScopeAuthorizer $authorizer,
        private FileService $files,
        private ReportExecutionClock $clock,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        CreateReportDownloadLinkData $data,
    ): ReportDownloadLink {
        $export = $this->exports->get($context, $data->exportId);
        if ($export->status === ReportExportStatus::EXPIRED || $export->expiresAt <= $this->clock->now()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_EXPIRED);
        }
        if ($export->status !== ReportExportStatus::READY
            || $export->artifactPath === null
            || $export->versionId === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
        }
        if (! str_starts_with(
            $export->artifactPath,
            'org-'.$context->scope->organizationId.'/',
        )) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $source = $this->runs->exportSource($context, $export->runId);
        $definition = $this->definitions->published($source->query->definition->code)->definition;
        if (! hash_equals($definition->definitionHash->value, $source->query->definition->definitionHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $this->authorize($context, $source, $definition, ReportOperation::DOWNLOAD);
        if ($definition->outputClassification->requiresSensitiveForColumns($export->columns)) {
            $this->authorize($context, $source, $definition, ReportOperation::VIEW_SENSITIVE);
        }
        if ($definition->outputClassification->requiresAuditForColumns($export->columns)) {
            $this->authorize($context, $source, $definition, ReportOperation::VIEW_AUDIT);
        }

        try {
            $temporary = $this->files->createTemporaryLink(
                $export->artifactPath,
                $export->versionId,
                $data->ttlSeconds,
            );
            if (! hash_equals($export->versionId, $temporary->versionId)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
            }

            return new ReportDownloadLink(
                $temporary->url,
                $temporary->versionId,
                $this->clock->now(),
                $temporary->expiresAt,
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

    private function authorize(
        ReportExecutionContext $context,
        \App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource $source,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
        ReportOperation $operation,
    ): void {
        $this->authorizer->authorizeExact(
            $context->actor->id,
            $source->query->scope,
            new CurrentReportAuthorizationTarget($definition, $operation, $source->snapshot),
        );
    }
}
