<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Modules\Events\TrialExpired;
use App\Notifications\TrialExpiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendTrialExpiredNotification implements ShouldQueue
{
    public function handle(TrialExpired $event): void
    {
        $activation = $event->activation;
        $organization = $activation->organization;

        if (!$organization) {
            return;
        }

        $owners = $organization->owners()->get();

        if ($owners->isEmpty()) {
            Log::warning('TrialExpired: у организации нет владельцев для уведомления', [
                'organization_id' => $organization->id,
                'module_id' => $activation->module_id,
            ]);
            return;
        }

        $notification = new TrialExpiredNotification($activation);

        foreach ($owners as $owner) {
            $owner->notify($notification);
        }

        Log::info('TrialExpired: уведомления отправлены владельцам организации', [
            'organization_id' => $organization->id,
            'module_id' => $activation->module_id,
            'notified_count' => $owners->count(),
        ]);
    }
}
