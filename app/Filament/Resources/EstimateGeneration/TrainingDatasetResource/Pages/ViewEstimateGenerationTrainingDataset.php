<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewEstimateGenerationTrainingDataset extends ViewRecord
{
    protected static string $resource = TrainingDatasetResource::class;

    public function getTitle(): string
    {
        return trans_message('estimate_generation.training_view_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('process')
                ->label(trans_message('estimate_generation.training_process_action'))
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (): bool => TrainingDatasetResource::canProcess())
                ->disabled(fn (): bool => ! $this->record instanceof EstimateGenerationTrainingDataset
                    || $this->record->status !== EstimateGenerationTrainingDataset::STATUS_DRAFT)
                ->action(function (): void {
                    if ($this->record instanceof EstimateGenerationTrainingDataset) {
                        TrainingDatasetResource::runAction($this->record, 'process');
                    }
                }),
        ];
    }
}
