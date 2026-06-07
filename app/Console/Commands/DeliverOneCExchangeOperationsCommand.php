<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OneCExchange\OneCExchangeDeliveryOrchestrator;
use Illuminate\Console\Command;

final class DeliverOneCExchangeOperationsCommand extends Command
{
    protected $signature = 'one-c-exchange:deliver
                            {--limit=50 : Maximum operations to process in this batch}
                            {--force : Run even when automatic delivery is disabled in configuration}';

    protected $description = 'Доставить готовые операции обмена 1С из журнальной очереди.';

    public function handle(OneCExchangeDeliveryOrchestrator $orchestrator): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('Лимит должен быть больше нуля.');

            return self::FAILURE;
        }

        if (! (bool) config('one_c_exchange.delivery.enabled', false) && ! (bool) $this->option('force')) {
            $this->info('Автоматическая доставка обмена 1С отключена.');

            return self::SUCCESS;
        }

        $summary = $orchestrator->run($limit);

        $this->info(sprintf(
            'Доставка обмена 1С: обработано=%d доставлено=%d запланировано_повторов=%d ручная_проверка=%d ошибок=%d',
            $summary->processed,
            $summary->delivered,
            $summary->retryScheduled,
            $summary->deadLettered,
            $summary->failed,
        ));

        return self::SUCCESS;
    }
}
