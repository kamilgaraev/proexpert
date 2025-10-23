<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Landing\OrganizationSubscriptionService;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Facades\Log;

class RenewSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected $organizationId;

    public function __construct(int $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    public function handle(OrganizationSubscriptionService $subscriptionService): void
    {
        try {
            $result = $subscriptionService->renewSubscription($this->organizationId);
            
            if ($result['success']) {
                Log::channel('business')->info('Subscription renewed successfully', [
                    'organization_id' => $this->organizationId,
                    'message' => $result['message'],
                ]);
            } else {
                Log::channel('business')->warning('Subscription renewal failed', [
                    'organization_id' => $this->organizationId,
                    'reason' => $result['reason'] ?? 'unknown',
                    'message' => $result['message'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('stderr')->error('Subscription renewal job failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('stderr')->critical('Subscription renewal job failed permanently', [
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
        ]);
    }
}

