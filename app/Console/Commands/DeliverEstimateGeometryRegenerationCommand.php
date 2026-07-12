<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntentStore;
use Illuminate\Console\Command;

final class DeliverEstimateGeometryRegenerationCommand extends Command
{
    protected $signature = 'estimate-generation:deliver-geometry-regeneration {--limit=100}';

    protected $description = 'Доставляет ожидающие задания пересчёта после подтверждения геометрии';

    public function handle(GeometryRegenerationIntentStore $outbox): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $this->components->info('Обработано заданий: '.$outbox->recover($limit));

        return self::SUCCESS;
    }
}
