<?php

declare(strict_types=1);

namespace App\Console\Commands\Assets;

use App\BusinessModules\Core\AssetManagement\Services\LegacyAssetMapper;
use Illuminate\Console\Command;

final class ReconcileOrganizationAssets extends Command
{
    protected $signature = 'assets:reconcile {--format=table : table или json}';

    protected $description = 'Сверяет legacy-источники с единым реестром имущества';

    public function handle(LegacyAssetMapper $mapper): int
    {
        $report = $mapper->reconcile();

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(array_keys($report), [array_values($report)]);
        }

        return $report['missing'] === 0 && $report['duplicates'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
