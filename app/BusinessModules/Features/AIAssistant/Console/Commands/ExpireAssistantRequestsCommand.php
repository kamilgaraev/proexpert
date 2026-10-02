<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use Illuminate\Console\Command;

final class ExpireAssistantRequestsCommand extends Command
{
    protected $signature = 'ai-assistant:requests-expire';

    protected $description = 'Освобождает резервы прерванных запросов помощника';

    public function handle(AssistantRequestLifecycle $requests): int
    {
        $requests->recoverQueued();
        $this->line((string) $requests->expireAbandoned());
        $requests->purgeExpiredPayloads();
        return self::SUCCESS;
    }
}
