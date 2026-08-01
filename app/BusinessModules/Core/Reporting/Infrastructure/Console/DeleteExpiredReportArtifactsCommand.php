<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Retention\DeleteExpiredReportArtifactsService;
use Illuminate\Console\Command;

use function trans_message;

final class DeleteExpiredReportArtifactsCommand extends Command
{
    protected $signature = 'reports:retention:delete-artifacts';

    protected $description;

    public function __construct(
        private readonly DeleteExpiredReportArtifactsService $service,
        private readonly ReportExecutionClock $clock,
        private readonly int $batchSize = 100,
    ) {
        parent::__construct();
        $this->description = trans_message('reports.commands.delete_expired_artifacts');
    }

    public function handle(): int
    {
        $summary = $this->service->delete($this->batchSize, $this->clock->now());
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
