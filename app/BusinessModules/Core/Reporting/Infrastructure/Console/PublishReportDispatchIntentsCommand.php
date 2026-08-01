<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Console;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentPublisher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function trans_message;

final class PublishReportDispatchIntentsCommand extends Command
{
    protected $signature = 'reports:dispatch-intents:publish';

    protected $description;

    public function __construct(
        private readonly ReportDispatchIntentPublisher $publisher,
        private readonly int $batchSize,
    ) {
        parent::__construct();
        $this->description = trans_message('reports.commands.publish_dispatch_intents');
        if ($batchSize < 1 || $batchSize > 500) {
            throw new InvalidArgumentException('report_dispatch_batch_size_invalid');
        }
    }

    public function handle(): int
    {
        $this->publisher->publishBatch(
            $this->batchSize,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return self::SUCCESS;
    }
}
