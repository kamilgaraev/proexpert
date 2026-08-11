<?php

declare(strict_types=1);

namespace App\Console\Commands\Assets;

use App\BusinessModules\Core\AssetManagement\Services\LegacyAssetMapper;
use Illuminate\Console\Command;

final class BackfillOrganizationAssets extends Command
{
    protected $signature = 'assets:backfill {--dry-run : Рассчитать изменения без записи} {--format=table : table или json}';

    protected $description = 'Детерминированно переносит legacy-технику и сериализованные складские записи в единый реестр';

    public function handle(LegacyAssetMapper $mapper): int
    {
        $report = $mapper->backfill((bool) $this->option('dry-run'));
        $this->renderReport($report);

        return $report['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(
            ['dry_run', 'scanned', 'would_create', 'created', 'links_updated', 'already_linked', 'periods_normalized', 'placements_reconciled', 'conflicts'],
            [[
                $report['dry_run'] ? 'yes' : 'no',
                $report['scanned'],
                $report['would_create'],
                $report['created'],
                $report['links_updated'],
                $report['already_linked'],
                $report['assignment_periods_normalized'],
                $report['placements_reconciled'],
                $report['conflicts'],
            ]],
        );
    }
}
