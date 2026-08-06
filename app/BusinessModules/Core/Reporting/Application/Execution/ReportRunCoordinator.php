<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class ReportRunCoordinator
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ReportSavedViewReferenceResolver $savedViews,
        private ReportAccessService $access,
        private ReportRunStore $runs,
        private ReportExecutionClock $clock,
        private ReportRunResponseRedactor $responseRedactor,
    ) {
    }

    public function create(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $idempotencyKey): ReportRun
    {
        $definition = $this->definitions->published($data->reportCode)->payload();
        $query = new ReportQuery($definition, $context->scope, $data->filters, $data->comparison, $data->asOf, $data->locale);
        $savedView = $data->savedViewId === null ? null : $this->savedViews->resolve($context, $data->savedViewId);
        $this->access->assertOperation($context, $definition, ReportOperation::RUN, null);

        return $this->runs->createOrReuse($context, $query, $savedView, $idempotencyKey);
    }

    public function get(ReportExecutionContext $context, string $runId): ReportRun
    {
        $run = $this->runs->get($context, $runId);
        $query = $this->runs->queryForRun($context, $runId);
        $visibility = $this->access->assertOperation(
            $context,
            $query->definition,
            ReportOperation::VIEW,
            null,
        );
        if ($query->definition->outputClassification->requiresSensitiveForSummary()) {
            $this->access->assertOperation($context, $query->definition, ReportOperation::VIEW_SENSITIVE, null);
        }
        if ($query->definition->outputClassification->requiresAuditForSummary()) {
            $this->access->assertOperation($context, $query->definition, ReportOperation::VIEW_AUDIT, null);
        }

        return $this->responseRedactor->redact(
            $run,
            $query->definition->outputClassification,
            $visibility,
        );
    }

    public function retry(ReportExecutionContext $context, string $runId, IdempotencyKey $key): ReportRun
    {
        $source = $this->runs->retrySource($context, $runId);
        $this->access->assertOperation($context, $source->query->definition, ReportOperation::RUN, null);
        if ($source->savedView !== null) {
            $this->savedViews->assertCurrent($context, $source->savedView);
        }

        return $this->runs->createOrReuse($context, $source->query, $source->savedView, $key);
    }

    public function cancel(ReportExecutionContext $context, string $runId): ReportRun
    {
        $query = $this->runs->queryForRun($context, $runId);
        $this->access->assertOperation($context, $query->definition, ReportOperation::RUN, null);

        return $this->runs->cancel($context, $runId, $this->clock->now());
    }
}
