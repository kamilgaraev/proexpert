<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;

final readonly class GetReportRowsHandler implements GetReportRowsAction
{
    public function __construct(
        private ReportRunStore $runs,
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingMap $bindings,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private SignedReportCursorCodec $cursors,
        private ReportExecutionClock $clock,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        string $runId,
        ReportRowsWindow $window,
    ): ReportPage {
        $run = $this->runs->get($context, $runId);
        $snapshot = $this->readySnapshot($run);
        $query = $this->runs->queryForRun($context, $runId);
        $this->assertIdentity($context, $runId, $run, $query, $snapshot);
        $binding = $this->binding($run, $query);
        $current = $this->authorizeRows($context, $query, $snapshot, $window);
        $providerContext = $this->contexts->fromCurrentAuthorization($current);
        $cursor = $window->cursor === null ? null : $this->cursors->decode(
            $window->cursor,
            $query->scope->organizationId,
            $run->reportCode,
            $run->id,
            $snapshot,
            $run->queryHash,
            $window->sort,
        );
        $page = $binding->rowQuery->page(
            $providerContext,
            $snapshot,
            $window->sort,
            $cursor,
            $window->limit,
        );
        if ($page->limit !== $window->limit
            || $page->sort->field !== $window->sort->field
            || $page->sort->direction !== $window->sort->direction) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $page = $this->withDrillDownTokens($page, $binding, $run, $query, $snapshot);

        return $this->withSignedNextCursor($page, $run, $query, $snapshot);
    }

    private function readySnapshot(ReportRun $run): ReportSnapshotRef
    {
        if ($run->status === ReportRunStatus::EXPIRED) {
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
        $binding = $this->bindings->get($run->reportCode);
        if (! hash_equals($binding->definitionHash->value, $query->definition->definitionHash->value)
            || ! hash_equals($binding->contractVersion, $query->definition->contractVersion)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $binding;
    }

    private function authorizeRows(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportRowsWindow $window,
    ): CurrentReportAuthorization {
        $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW);
        $classification = $query->definition->outputClassification;
        if ($classification->requiresSensitiveForRows()
            || $classification->requiresSensitiveForColumns([$window->sort->field])) {
            $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW_SENSITIVE);
        }
        if ($classification->requiresAuditForRows()
            || $classification->requiresAuditForColumns([$window->sort->field])) {
            $authorization = $this->authorize($context, $query, $snapshot, ReportOperation::VIEW_AUDIT);
        }

        return $authorization;
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

    private function withSignedNextCursor(
        ReportPage $page,
        ReportRun $run,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
    ): ReportPage {
        if (! $page->hasMore) {
            if ($page->nextCursor === null) {
                return $page;
            }

            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $last = $page->rows[array_key_last($page->rows)] ?? null;
        if (! is_array($last)
            || ! isset($last['row_key'])
            || ! is_string($last['row_key'])
            || ! array_key_exists($page->sort->field, $last)
            || (! is_scalar($last[$page->sort->field]) && $last[$page->sort->field] !== null)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $token = $this->cursors->encode(
            $query->scope->organizationId,
            $run->reportCode,
            $run->id,
            $snapshot,
            $run->queryHash,
            $page->sort,
            $last[$page->sort->field],
            $last['row_key'],
            $this->clock->now()->modify('+5 minutes'),
        );

        return new ReportPage(
            $page->rows,
            $page->totals,
            $page->freshness,
            $page->quality,
            $token,
            $page->limit,
            true,
            $page->sort,
        );
    }

    private function withDrillDownTokens(
        ReportPage $page,
        ReportDefinitionBinding $binding,
        ReportRun $run,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
    ): ReportPage {
        if (! $binding->drillDownProvider instanceof ReportDrillDownTokenColumns) {
            return $page;
        }

        $columns = $binding->drillDownProvider->drillDownTokenColumns();
        if ($columns === []) {
            return $page;
        }
        $definitionColumns = array_fill_keys(array_column($query->definition->columns, 'id'), true);
        $publishedColumns = [];
        foreach ($columns as $outputColumn => $providerColumn) {
            if (! is_string($outputColumn)
                || ! is_string($providerColumn)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $outputColumn) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $providerColumn) !== 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            if (isset($definitionColumns[$outputColumn])) {
                $publishedColumns[$outputColumn] = $providerColumn;
            }
        }
        if ($publishedColumns === []) {
            return $page;
        }

        $rows = array_map(function (array $row) use ($publishedColumns, $run, $query, $snapshot): array {
            foreach ($publishedColumns as $outputColumn => $providerColumn) {
                $row[$outputColumn] = $this->cursors->encodeDrillDownCell(
                    $query->scope->organizationId,
                    $run->reportCode,
                    $run->id,
                    $snapshot,
                    $run->queryHash,
                    $row['row_key'],
                    $providerColumn,
                    $this->clock->now()->modify('+5 minutes'),
                );
            }

            return $row;
        }, $page->rows);

        return new ReportPage(
            $rows,
            $page->totals,
            $page->freshness,
            $page->quality,
            $page->nextCursor,
            $page->limit,
            $page->hasMore,
            $page->sort,
        );
    }
}
