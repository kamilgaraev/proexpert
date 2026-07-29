<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\Services\Storage\DTO\StoredFile;
use DateTimeImmutable;

interface ReportExportStore
{
    public function createOrReuse(ReportExecutionContext $context, ReportRunExportSource $source, CreateReportExportData $data, IdempotencyKey $idempotencyKey, ReportAuthorizationFence $fence): ReportExport;

    public function get(ReportExecutionContext $context, string $exportId): ReportExport;

    public function startRendering(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport;

    public function startUploading(ReportExecutionContext $context, string $exportId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportExport;

    public function sealReady(ReportExecutionContext $context, string $exportId, string $leaseToken, StoredFile $artifact, int $rowCount, DateTimeImmutable $occurredAt): ReportExport;

    public function fail(ReportExecutionContext $context, string $exportId, ?string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportExport;

    public function cancel(ReportExecutionContext $context, string $exportId, DateTimeImmutable $occurredAt, ReportAuthorizationFence $fence): ReportExport;

}
