<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGenerationTrainingDatasetResource\Pages;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use App\Filament\Resources\EstimateGenerationTrainingDatasetResource;
use App\Models\SystemAdmin;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateEstimateGenerationTrainingDataset extends CreateRecord
{
    protected static string $resource = EstimateGenerationTrainingDatasetResource::class;

    public function getTitle(): string
    {
        return trans_message('estimate_generation.training_create_title');
    }

    protected function handleRecordCreation(array $data): EstimateGenerationTrainingDataset
    {
        $actor = Auth::guard('system_admin')->user();
        $dataset = app(EstimateGenerationTrainingDatasetService::class)
            ->createFromFilament($data, $actor instanceof SystemAdmin ? $actor : null);

        if ((bool) ($data['auto_process'] ?? true)
            && EstimateGenerationTrainingDatasetResource::canProcess()) {
            app(EstimateGenerationTrainingDatasetService::class)->queueProcessing($dataset);
        }

        return $dataset;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(trans_message('estimate_generation.training_created'));
    }
}
