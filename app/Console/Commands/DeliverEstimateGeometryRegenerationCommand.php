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
        $result = $outbox->recover($limit);
        $this->components->info(sprintf('Получено: %d; доставлено: %d; отложено: %d', $result['claimed'], $result['delivered'], $result['failed']));

        return self::SUCCESS;
    }
}
