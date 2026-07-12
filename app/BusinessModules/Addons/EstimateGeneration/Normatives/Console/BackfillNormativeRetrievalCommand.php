<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalBackfillService;
use Illuminate\Console\Command;

final class BackfillNormativeRetrievalCommand extends Command
{
    protected $signature = 'estimate-generation:normative-retrieval-backfill {--batch=1000}';

    protected $description = 'Безопасно заполняет поля поиска нормативов одной возобновляемой порцией';

    public function handle(NormativeRetrievalBackfillService $service): int
    {
        $result = $service->resume((int) $this->option('batch'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
