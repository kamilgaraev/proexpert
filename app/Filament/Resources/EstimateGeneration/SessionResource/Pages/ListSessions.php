<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\Pages;

use App\Filament\Resources\EstimateGeneration\SessionResource;
use Filament\Resources\Pages\ListRecords;

class ListSessions extends ListRecords
{
    protected static string $resource = SessionResource::class;
}
