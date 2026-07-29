<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Jobs;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $exportId)
    {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $exportId) !== 1) {
            throw new \InvalidArgumentException('report_export_id_invalid');
        }

        $this->onConnection('redis_reports');
        $this->onQueue('reports');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(ReportExportExecutionService $service): void
    {
        $service->execute($this->exportId, $this->envelopeUuid());
    }

    private function envelopeUuid(): string
    {
        $uuid = $this->job?->uuid();
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return strtolower($uuid);
    }
}
