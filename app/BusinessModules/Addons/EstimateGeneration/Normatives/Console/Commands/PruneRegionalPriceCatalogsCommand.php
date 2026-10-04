<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Retention\RegionalPriceCatalogRetentionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PruneRegionalPriceCatalogsCommand extends Command
{
    protected $signature = 'estimates:prune-price-catalogs
        {--execute : Delete eligible prices; the default only reports candidates}
        {--quarters=4 : Published quarters to retain per source, region and price zone (minimum 4)}
        {--failed-days=45 : Grace for failed imports and replaced publications (minimum 45)}
        {--limit=100000 : Maximum price rows deleted per invocation}
        {--batch=1000 : Maximum price rows deleted per transaction (maximum 5000)}
        {--max-seconds=240 : Maximum work duration in seconds (maximum 600)}';

    protected $description = 'Очистить устаревшие региональные цены, сохранив все используемые источники.';

    public function handle(RegionalPriceCatalogRetentionService $service): int
    {
        $options = [];
        foreach (['quarters', 'failed-days', 'limit', 'batch', 'max-seconds'] as $option) {
            $value = $this->option($option);
            if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 9) {
                $this->error('Invalid retention option: '.$option);
                return self::INVALID;
            }
            $options[] = (int) $value;
        }
        try {
            $result = $service->prune((bool) $this->option('execute'), ...$options);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::INVALID;
        }
        $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
