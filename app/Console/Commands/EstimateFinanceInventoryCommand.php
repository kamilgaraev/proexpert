<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceMigrationInventory;
use Illuminate\Console\Command;

final class EstimateFinanceInventoryCommand extends Command
{
    protected $signature = 'estimates:finance-inventory {--organization_id=} {--after=0}';

    protected $description = 'Read-only inventory of preserved legacy estimate links (JSON Lines)';

    public function handle(EstimateFinanceMigrationInventory $inventory): int
    {
        $organization = $this->option('organization_id');
        $after = $this->option('after');
        if (($organization !== null && (! ctype_digit((string) $organization) || (int) $organization < 1))
            || ! ctype_digit((string) $after)) {
            $this->error('Invalid organization_id or after');

            return self::INVALID;
        }
        do {
            $page = $inventory->page($organization === null ? null : (int) $organization, (int) $after);
            $this->line(json_encode($page, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $after = $page['next_cursor'];
        } while ($after !== null);

        return self::SUCCESS;
    }
}
