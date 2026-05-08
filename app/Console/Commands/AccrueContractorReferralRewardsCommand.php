<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Contractor\ContractorReferralRewardService;
use Illuminate\Console\Command;

class AccrueContractorReferralRewardsCommand extends Command
{
    protected $signature = 'contractor-referrals:accrue';

    protected $description = 'Accrue contractor referral rewards after the first paid subscription period ends';

    public function handle(ContractorReferralRewardService $service): int
    {
        $count = $service->accrueEligibleRewards();

        $this->info("Accrued contractor referral rewards: {$count}");

        return self::SUCCESS;
    }
}
