<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource;
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

interface ReportRunStore
{
    public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?ReportSavedViewRef $savedView, IdempotencyKey $idempotencyKey): ReportRun;

    public function get(ReportExecutionContext $context, string $runId): ReportRun;

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery;

    public function retrySource(ReportExecutionContext $context, string $runId): ReportRunRetrySource;

    public function exportSource(ReportExecutionContext $context, string $runId): ReportRunExportSource;

    public function claimMaterialization(ReportExecutionContext $context, string $runId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun;

    public function persistProgress(ReportExecutionContext $context, string $runId, string $leaseToken, ReportProgress $progress, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun;

    public function sealReady(ReportExecutionContext $context, string $runId, string $leaseToken, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun;

    public function fail(ReportExecutionContext $context, string $runId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun;

    public function cancel(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun;
}
