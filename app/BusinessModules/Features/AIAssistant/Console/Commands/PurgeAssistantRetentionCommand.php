<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRetentionService;
use Illuminate\Console\Command;

class PurgeAssistantRetentionCommand extends Command
{
    protected $signature = 'ai-assistant:purge-retention {--days=90} {--execute}';

    protected $description = 'Показывает объём истекших данных помощника; удаление выполняется только с --execute';

    public function handle(AssistantRetentionService $retention): int
    {
        $result = $this->option('execute') ? $retention->purge((int) $this->option('days')) : $retention->preview((int) $this->option('days'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
