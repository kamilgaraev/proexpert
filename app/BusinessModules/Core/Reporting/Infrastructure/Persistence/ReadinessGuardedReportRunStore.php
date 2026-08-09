<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource;
use App\BusinessModules\Core\Reporting\Application\Readiness\ReportCandidateReadinessGate;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

final readonly class ReadinessGuardedReportRunStore implements ReportRunStore
{
    public function __construct(
        private ReportRunStore $runs,
        private ReportDefinitionBindingMap $bindings,
        private ReportCandidateReadinessGate $gate,
    ) {}

    public function createOrReuse(
        ReportExecutionContext $context,
        ReportQuery $query,
        ?ReportSavedViewRef $savedView,
        IdempotencyKey $idempotencyKey,
    ): ReportRun {
        $binding = $this->bindings->get($query->definition->code);
        $probe = $binding->readinessProbe;
        if ($probe instanceof ReportSourceReadinessProbe) {
            $this->gate->assertReady(
                $query->definition->code,
                $probe->inspect($context, $query),
            );
        }

        return $this->runs->createOrReuse($context, $query, $savedView, $idempotencyKey);
    }

    public function get(ReportExecutionContext $context, string $runId): ReportRun
    {
        return $this->runs->get($context, $runId);
    }

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery
    {
        return $this->runs->queryForRun($context, $runId);
    }

    public function retrySource(ReportExecutionContext $context, string $runId): ReportRunRetrySource
    {
        return $this->runs->retrySource($context, $runId);
    }

    public function exportSource(ReportExecutionContext $context, string $runId): ReportRunExportSource
    {
        return $this->runs->exportSource($context, $runId);
    }

    public function claimMaterialization(
        ReportExecutionContext $context,
        string $runId,
        string $leaseToken,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportRun {
        return $this->runs->claimMaterialization($context, $runId, $leaseToken, $leaseExpiresAt, $occurredAt);
    }

    public function persistProgress(
        ReportExecutionContext $context,
        string $runId,
        string $leaseToken,
        ReportProgress $progress,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportRun {
        return $this->runs->persistProgress($context, $runId, $leaseToken, $progress, $leaseExpiresAt, $occurredAt);
    }

    public function sealReady(
        ReportExecutionContext $context,
        string $runId,
        string $leaseToken,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        Sha256Hash $sourceHash,
        DateTimeImmutable $occurredAt,
    ): ReportRun {
        return $this->runs->sealReady($context, $runId, $leaseToken, $snapshot, $result, $sourceHash, $occurredAt);
    }

    public function fail(
        ReportExecutionContext $context,
        string $runId,
        ?string $leaseToken,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): ReportRun {
        return $this->runs->fail($context, $runId, $leaseToken, $errorCode, $occurredAt);
    }

    public function cancel(
        ReportExecutionContext $context,
        string $runId,
        DateTimeImmutable $occurredAt,
    ): ReportRun {
        return $this->runs->cancel($context, $runId, $occurredAt);
    }
}
