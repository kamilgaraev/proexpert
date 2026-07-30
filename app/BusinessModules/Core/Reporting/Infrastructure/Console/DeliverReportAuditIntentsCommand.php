<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportAuditOutboxScheduler;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use Illuminate\Console\Command;

use function trans_message;

final class DeliverReportAuditIntentsCommand extends Command
{
    protected $signature = 'reports:audit-intents:deliver';

    protected $description;

    public function __construct(
        private readonly ReportAuditOutboxScheduler $scheduler,
        private readonly ReportAuditIntentStore $store,
        private readonly ReportExecutionClock $clock,
        private readonly ReportExecutionTelemetry $telemetry,
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {
        parent::__construct();
        $this->description = trans_message('reports.commands.deliver_audit_intents');
    }

    public function handle(): int
    {
        $now = $this->clock->now();
        $reclaimed = $this->store->reclaimExpired(
            $this->runtime->auditBatchSize,
            $now,
        );
        for ($index = 0; $index < $reclaimed; $index++) {
            $this->telemetry->dispatchIntent('audit', 'append_audit', 'reclaimed', 0.0);
        }
        $count = $this->scheduler->dispatchDue($this->runtime->auditBatchSize, $now);
        $this->line(json_encode([
            'reclaimed' => $reclaimed,
            'dispatched' => $count,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
