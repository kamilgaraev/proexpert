<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGenerationTrainingDatasetResource\Pages;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use App\Filament\Resources\EstimateGenerationTrainingDatasetResource;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEstimateGenerationTrainingDataset extends ViewRecord
{
    protected static string $resource = EstimateGenerationTrainingDatasetResource::class;

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
                ->visible(fn (): bool => SystemAdminAccess::can(FilamentPermission::AI_ESTIMATOR_TRAINING_PROCESS))
                ->disabled(fn (): bool => ! $this->record instanceof EstimateGenerationTrainingDataset
                    || $this->record->status !== EstimateGenerationTrainingDataset::STATUS_DRAFT)
                ->action(function (): void {
                    if ($this->record instanceof EstimateGenerationTrainingDataset) {
                        app(EstimateGenerationTrainingDatasetService::class)->queueProcessing($this->record);
                    }

                    Notification::make()
                        ->success()
                        ->title(trans_message('estimate_generation.training_process_queued'))
                        ->send();
                }),
        ];
    }
}
