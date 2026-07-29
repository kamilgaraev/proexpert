<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;

final readonly class GetReportDrillDownHandler implements GetReportDrillDownAction
{
    public function __construct(
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingAssembler $bindings,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private SignedReportCursorCodec $tokens,
        private ReportExecutionClock $clock,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        string $runId,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $run = $this->runs->get($context, $runId);
        $snapshot = $this->readySnapshot($run);
        $query = $this->runs->queryForRun($context, $runId);
        $this->assertIdentity($context, $runId, $run, $query, $snapshot);
        $binding = $this->binding($run, $query);
        $cell = $this->tokens->decodeDrillDownCell(
            $request->token,
            $query->scope->organizationId,
            $run->reportCode,
            $run->id,
            $snapshot,
            $run->queryHash,
        );
        if (! in_array($cell['column_id'], array_column($query->definition->columns, 'id'), true)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_CURSOR_INVALID,
                ['fields' => ['token']],
            );
        }
        $authorization = $this->authorizeDrillDown($context, $query, $snapshot);
        $providerContext = $this->contexts->fromCurrentAuthorization($authorization);

        return $binding->drillDownProvider->drillDown($providerContext, $snapshot, $request);
    }

    private function readySnapshot(ReportRun $run): ReportSnapshotRef
    {
        if ($run->status === ReportRunStatus::EXPIRED || $run->expiresAt <= $this->clock->now()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }
        if ($run->status !== ReportRunStatus::READY || $run->resultMetadata === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $run->resultMetadata->snapshot;
    }

    private function assertIdentity(
        ReportExecutionContext $context,
        string $runId,
        ReportRun $run,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
    ): void {
        $definition = $query->definition;
        if (! hash_equals($runId, $run->id)
            || ! hash_equals($run->reportCode, $definition->code)
            || ! hash_equals($run->definitionHash->value, $definition->definitionHash->value)
            || ! hash_equals($run->contractVersion, $definition->contractVersion)
            || ! hash_equals($run->formulaVersion, $definition->formulaVersion)
            || ! hash_equals($run->sourceSchemaVersion, $definition->sourceSchemaVersion)
            || ! hash_equals($run->rendererVersion, $definition->rendererVersion)
            || ! hash_equals($run->queryHash->value, $query->queryHash->value)
            || $run->sourceHash === null
            || ! hash_equals($run->sourceHash->value, $snapshot->sourceHash->value)
            || ! hash_equals($run->definitionHash->value, $snapshot->definitionHash->value)
            || ! hash_equals($run->formulaVersion, $snapshot->formulaVersion)
            || $query->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
    }

    private function binding(ReportRun $run, ReportQuery $query): ReportDefinitionBinding
    {
        $binding = $this->bindings->assemble($this->definitions)->get($run->reportCode);
        if (! hash_equals($binding->definitionHash->value, $query->definition->definitionHash->value)
            || ! hash_equals($binding->contractVersion, $query->definition->contractVersion)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $binding;
    }

    private function authorizeDrillDown(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
    ): CurrentReportAuthorization {
        $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW);
        $classification = $query->definition->outputClassification;
        if ($classification->requiresSensitiveForRows()) {
            $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW_SENSITIVE);
        }
        if ($classification->requiresAuditForRows()) {
            $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW_AUDIT);
        }

        return $this->authorize($context, $query, $snapshot, ReportOperation::DRILL_DOWN);
    }

    private function authorize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportOperation $operation,
    ): CurrentReportAuthorization {
        return $this->authorizer->authorizeExact(
            $context->actor->id,
            $query->scope,
            new CurrentReportAuthorizationTarget($query->definition, $operation, $snapshot),
        );
    }
}
