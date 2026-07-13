<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\FailureResource\Pages;

use App\Filament\Resources\EstimateGeneration\FailureResource;
use Filament\Resources\Pages\ListRecords;

class ListFailures extends ListRecords
{
    protected static string $resource = FailureResource::class;
}
