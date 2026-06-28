<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGenerationTrainingDatasetResource\Pages;

use App\Filament\Resources\EstimateGenerationTrainingDatasetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEstimateGenerationTrainingDatasets extends ListRecords
{
    protected static string $resource = EstimateGenerationTrainingDatasetResource::class;

    public function getTitle(): string
    {
        return trans_message('estimate_generation.training_list_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
