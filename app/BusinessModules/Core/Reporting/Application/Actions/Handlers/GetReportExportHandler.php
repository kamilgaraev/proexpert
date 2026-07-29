<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

final readonly class GetReportExportHandler implements GetReportExportAction
{
    public function __construct(
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private CurrentReportScopeAuthorizer $authorizer,
    ) {}

    public function handle(ReportExecutionContext $context, string $exportId): ReportExport
    {
        $export = $this->exports->get($context, $exportId);
        $query = $this->runs->queryForRun($context, $export->runId);
        $definition = $this->definitions->published($query->definition->code)->definition;
        if (! hash_equals($definition->definitionHash->value, $query->definition->definitionHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $this->authorize($context, $query->scope, $definition, ReportOperation::VIEW);
        if ($definition->outputClassification->requiresSensitiveForColumns($export->columns)) {
            $this->authorize($context, $query->scope, $definition, ReportOperation::VIEW_SENSITIVE);
        }
        if ($definition->outputClassification->requiresAuditForColumns($export->columns)) {
            $this->authorize($context, $query->scope, $definition, ReportOperation::VIEW_AUDIT);
        }

        return $export;
    }

    private function authorize(
        ReportExecutionContext $context,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope $scope,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
        ReportOperation $operation,
    ): void {
        $this->authorizer->authorizeExact(
            $context->actor->id,
            $scope,
            new CurrentReportAuthorizationTarget($definition, $operation, null),
        );
    }
}
