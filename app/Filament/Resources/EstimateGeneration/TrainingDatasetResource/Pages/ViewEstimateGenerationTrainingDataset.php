<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages;

use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEstimateGenerationTrainingDataset extends ViewRecord
{
    protected static string $resource = TrainingDatasetResource::class;

    public function getTitle(): string
    {
        return trans_message('estimate_generation.training_view_title');
    }
}
