<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\PipelineCheckpointResource\Pages;

use App\Filament\Resources\EstimateGeneration\PipelineCheckpointResource;
use Filament\Resources\Pages\ListRecords;

class ListPipelineCheckpoints extends ListRecords
{
    protected static string $resource = PipelineCheckpointResource::class;
}
