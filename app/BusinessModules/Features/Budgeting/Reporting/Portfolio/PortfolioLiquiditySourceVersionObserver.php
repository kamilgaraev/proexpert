<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use Illuminate\Database\Eloquent\Model;

final readonly class PortfolioLiquiditySourceVersionObserver
{
    public function __construct(private PortfolioLiquiditySourceVersionRecorder $recorder) {}

    public function created(Model $source): void
    {
        $this->recorder->record($source, $source->getAttribute('created_at'));
    }

    public function updated(Model $source): void
    {
        $this->recorder->record($source, $source->getAttribute('updated_at'));
    }

    public function deleted(Model $source): void
    {
        $this->recorder->record($source, now(), true);
    }

    public function restored(Model $source): void
    {
        $this->recorder->record($source, now());
    }
}
