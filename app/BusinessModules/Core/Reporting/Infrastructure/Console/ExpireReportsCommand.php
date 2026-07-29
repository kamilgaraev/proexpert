<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Retention\ExpireReportsService;
use Illuminate\Console\Command;

final class ExpireReportsCommand extends Command
{
    protected $signature = 'reports:retention:expire';

    protected $description = 'Завершает срок хранения готовых отчётов и экспортов';

    public function __construct(
        private readonly ExpireReportsService $service,
        private readonly ReportExecutionClock $clock,
        private readonly int $batchSize = 100,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->service->expire($this->batchSize, $this->clock->now());
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
