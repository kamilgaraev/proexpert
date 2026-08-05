<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use DateTimeInterface;

final readonly class PortfolioLiquidityBudgetVersionObserver
{
    public function __construct(private PortfolioLiquiditySourceVersionRecorder $recorder) {}

    public function updated(BudgetVersion $version): void
    {
        if (! $version->wasChanged('status')) {
            return;
        }

        $occurredAt = $version->updated_at instanceof DateTimeInterface ? $version->updated_at : now();
        $version->amounts()
            ->with(['line.version', 'line.article'])
            ->orderBy('budget_amounts.id')
            ->chunkById(500, function ($amounts) use ($occurredAt): void {
                foreach ($amounts as $amount) {
                    $this->recorder->record($amount, $occurredAt);
                }
            }, 'budget_amounts.id', 'id');
    }
}
