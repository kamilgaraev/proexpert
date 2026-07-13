<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\BenchmarkRunResource\Pages;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunDetailService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewBenchmarkRun extends ViewRecord
{
    protected static string $resource = BenchmarkRunResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        if ($this->record instanceof EstimateGenerationBenchmarkRun) {
            $this->record->forceFill(app(BenchmarkRunDetailService::class)->present($this->record));
        }
    }
}
