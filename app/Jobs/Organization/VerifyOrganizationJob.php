<?php

namespace App\Jobs\Organization;

use App\Models\Organization;
use App\Services\OrganizationVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения задания.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Количество секунд ожидания перед повторной попыткой.
     *
     * @var int
     */
    public $backoff = 60;

    public function __construct(
        private readonly Organization $organization
    ) {}

    public function handle(OrganizationVerificationService $verificationService): void
    {
        Log::info('Starting background verification for organization', [
            'organization_id' => $this->organization->id
        ]);

        try {
            // Проверяем, что данные все еще актуальны для верификации
            if (!$this->organization->canBeVerified()) {
                Log::info('Organization cannot be verified (missing data)', [
                    'organization_id' => $this->organization->id
                ]);
                return;
            }

            $result = $verificationService->requestVerification($this->organization);

            Log::info('Background verification completed', [
                'organization_id' => $this->organization->id,
                'success' => $result['success']
            ]);

        } catch (\Exception $e) {
            Log::error('Background verification failed', [
                'organization_id' => $this->organization->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e; // Чтобы Job попал в failed_jobs после попыток
        }
    }
}

