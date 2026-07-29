<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportAuditOutboxScheduler;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use Illuminate\Console\Command;

final class DeliverReportAuditIntentsCommand extends Command
{
    protected $signature = 'reports:audit-intents:deliver';

    protected $description = 'Передаёт ожидающие события отчётов в журнал аудита';

    public function __construct(
        private readonly ReportAuditOutboxScheduler $scheduler,
        private readonly ReportExecutionClock $clock,
        private readonly int $batchSize = 100,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->scheduler->dispatchDue($this->batchSize, $this->clock->now());
        $this->line(json_encode(['dispatched' => $count], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
