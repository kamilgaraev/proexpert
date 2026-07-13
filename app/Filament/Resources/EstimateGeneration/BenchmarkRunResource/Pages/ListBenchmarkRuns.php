<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\BenchmarkRunResource\Pages;

use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource;
use Filament\Resources\Pages\ListRecords;

final class ListBenchmarkRuns extends ListRecords
{
    protected static string $resource = BenchmarkRunResource::class;

    protected function getHeaderActions(): array
    {
        return [BenchmarkRunResource::runBenchmarkAction()];
    }
}
