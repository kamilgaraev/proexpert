<?php

namespace App\BusinessModules\Addons\AIEstimates\Listeners;

use App\BusinessModules\Addons\AIEstimates\Events\EstimateGenerationCompleted;
use App\BusinessModules\Addons\AIEstimates\Events\EstimateGenerationFailed;
use Illuminate\Support\Facades\Log;

class NotifyUserAboutGeneration
{
    public function handleCompleted(EstimateGenerationCompleted $event): void
    {
        Log::info('[NotifyUserAboutGeneration] Generation completed', [
            'generation_id' => $event->generation->id,
            'user_id' => $event->generation->user_id,
        ]);

        // TODO: Отправить уведомление пользователю через систему уведомлений
        // app(NotificationService::class)->notify(...)
    }

    public function handleFailed(EstimateGenerationFailed $event): void
    {
        Log::error('[NotifyUserAboutGeneration] Generation failed', [
            'generation_id' => $event->generation->id,
            'user_id' => $event->generation->user_id,
            'error' => $event->errorMessage,
        ]);

        // TODO: Отправить уведомление об ошибке
    }

    public function subscribe($events): array
    {
        return [
            EstimateGenerationCompleted::class => 'handleCompleted',
            EstimateGenerationFailed::class => 'handleFailed',
        ];
    }
}
