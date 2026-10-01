<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Console\Command;

final class RecoverRagIndexRunsCommand extends Command
{
    protected $signature = 'ai-assistant:rag-recover';
    protected $description = 'Повторно ставит в очередь просроченные запуски индекса знаний';

    public function handle(
        RagIndexingCoordinator $coordinator,
        GlobalRagQueue $global,
        AssistantNativeAttachmentPreparationQueue $nativePreparation,
    ): int {
        $this->line('Recovered RAG index runs: '.$coordinator->recoverExpiredRuns());
        $this->line('Dispatched bounded native attachment preparation runs: '.$nativePreparation->dispatchPendingNativeRuns());
        $this->line('Recovered global RAG events: '.$global->recoverPending());

        return self::SUCCESS;
    }
}
