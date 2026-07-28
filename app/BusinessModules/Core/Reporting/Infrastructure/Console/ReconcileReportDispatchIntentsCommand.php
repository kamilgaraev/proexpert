<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentReconciler;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReconcileReportDispatchIntentsCommand extends Command
{
    protected $signature = 'reports:dispatch-intents:reconcile';

    protected $description = 'Восстанавливает и публикует задания формирования отчётов';

    public function __construct(
        private readonly ReportDispatchIntentReconciler $reconciler,
        private readonly int $batchSize,
    ) {
        parent::__construct();
        if ($batchSize < 1 || $batchSize > 500) {
            throw new InvalidArgumentException('report_dispatch_batch_size_invalid');
        }
    }

    public function handle(): int
    {
        $this->reconciler->reconcile(
            $this->batchSize,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return self::SUCCESS;
    }
}
