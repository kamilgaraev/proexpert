<?php

namespace App\Console\Commands;

use App\Services\Contractor\ContractorInvitationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireContractorInvitations extends Command
{
    protected $signature = 'invitations:expire-contractor';
    protected $description = 'Mark expired contractor invitations as expired';

    protected ContractorInvitationService $invitationService;

    public function __construct(ContractorInvitationService $invitationService)
    {
        parent::__construct();
        $this->invitationService = $invitationService;
    }

    public function handle(): int
    {
        $this->info('Starting contractor invitations expiration process...');

        try {
            $expiredCount = $this->invitationService->expireOldInvitations();

            $this->info("Successfully expired {$expiredCount} contractor invitations.");
            
            Log::info('Contractor invitations expiration completed', [
                'expired_count' => $expiredCount,
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to expire contractor invitations: ' . $e->getMessage());
            
            Log::error('Contractor invitations expiration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}