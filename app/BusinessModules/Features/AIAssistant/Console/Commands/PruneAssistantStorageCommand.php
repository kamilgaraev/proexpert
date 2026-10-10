<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\Rag\AssistantStoragePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class PruneAssistantStorageCommand extends Command
{
    protected $signature = 'ai-assistant:prune-storage {--max-batches=50}';

    protected $description = 'Очищает служебный журнал и копии справочников ограниченными пакетами';

    public function handle(AssistantStoragePruner $pruner): int
    {
        $batches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($batches === false) {
            $this->error('max-batches должен быть от 1 до 50.');
            return self::INVALID;
        }
        $lock = Cache::lock('ai-assistant:storage-prune', 60);
        if (! $lock->get()) { return self::SUCCESS; }
        try {
            $this->line(json_encode($pruner->prune($batches, microtime(true) + 40), JSON_THROW_ON_ERROR));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
