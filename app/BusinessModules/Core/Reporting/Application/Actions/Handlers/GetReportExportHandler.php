<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

final readonly class GetReportExportHandler implements GetReportExportAction
{
    public function __construct(
        private ReportExportStore $exports,
        private ReportDefinitionRegistry $definitions,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private ReportAuthorizationSubjectReader $subjects,
    ) {}

    public function handle(ReportExecutionContext $context, string $exportId): ReportExport
    {
        $subject = $this->subjects->export($exportId);
        ReportAuthorizationFence::assertExactScope($context, $subject);
        $export = $this->exports->get($context, $exportId);
        $definition = $this->definitions->published($subject->definition->code)->definition;
        if (! hash_equals($definition->definitionHash->value, $subject->definition->definitionHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $operations = [ReportOperation::VIEW];
        if ($definition->outputClassification->requiresSensitiveForColumns($export->columns)) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if ($definition->outputClassification->requiresAuditForColumns($export->columns)) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }
        (new ReportAuthorizationFence(
            $subject,
            $operations,
            $this->authorizer,
            $this->contexts,
        ))->assertCurrent($context);

        return $export;
    }
}
