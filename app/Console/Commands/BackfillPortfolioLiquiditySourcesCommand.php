<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBackfillRunner;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquiditySourceVersionBackfill;
use Illuminate\Console\Command;

final class BackfillPortfolioLiquiditySourcesCommand extends Command
{
    protected $signature = 'reports:backfill-portfolio-liquidity
        {organization_id : Organization identifier}
        {--source= : One canonical source type}
        {--chunk=500 : Rows per durable checkpoint}
        {--max-chunks=1000 : Maximum chunks per invocation}';

    protected $description = 'Projects canonical portfolio-liquidity source history with durable checkpoints';

    public function handle(
        PortfolioLiquidityBackfillRunner $runner,
        PortfolioLiquiditySourceVersionBackfill $backfill,
    ): int {
        $organizationId = (int) $this->argument('organization_id');
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $maxChunks = max(1, (int) $this->option('max-chunks'));
        $requestedSource = $this->option('source');
        $sources = is_string($requestedSource) && $requestedSource !== ''
            ? [$requestedSource]
            : $backfill->supportedSourceTypes();

        foreach ($sources as $sourceType) {
            $complete = false;
            for ($chunkNumber = 0; $chunkNumber < $maxChunks; $chunkNumber++) {
                $result = $runner->runChunk($organizationId, $sourceType, $chunk);
                if ($result['has_more'] === false) {
                    $complete = true;
                    break;
                }
            }
            if (! $complete) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
