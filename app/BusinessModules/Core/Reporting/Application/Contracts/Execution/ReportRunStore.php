<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

interface ReportRunStore
{
    public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?string $savedViewId, IdempotencyKey $idempotencyKey): ReportRun;

    public function get(ReportExecutionContext $context, string $runId): ReportRun;

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery;

    public function startMaterialization(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun;

    public function persistProgress(ReportExecutionContext $context, string $runId, ReportProgress $progress, DateTimeImmutable $occurredAt): ReportRun;

    public function sealReady(ReportExecutionContext $context, string $runId, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun;

    public function fail(ReportExecutionContext $context, string $runId, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun;

    public function cancel(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun;
}
