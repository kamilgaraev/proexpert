<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\UsageResource\Pages;

use App\Filament\Resources\EstimateGeneration\UsageResource;
use Filament\Resources\Pages\ListRecords;

class ListUsage extends ListRecords
{
    protected static string $resource = UsageResource::class;
}
