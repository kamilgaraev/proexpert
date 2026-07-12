<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalRolloutService;
use Illuminate\Console\Command;

final class RolloutNormativeRetrievalCommand extends Command
{
    protected $signature = 'estimate-generation:normative-retrieval-rollout {action=status : status|deploy}';

    protected $description = 'Показывает состояние или включает подготовленный поиск нормативов';

    public function handle(NormativeRetrievalRolloutService $service): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'deploy'], true)) {
            return self::INVALID;
        }
        $this->line(json_encode($action === 'deploy' ? $service->deploy() : $service->status(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
