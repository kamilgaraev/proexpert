<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Models\CounterpartyAccount;
use App\BusinessModules\Core\Payments\Services\CounterpartyAccountService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateCounterpartyBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $accountId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CounterpartyAccountService $service): void
    {
        $account = CounterpartyAccount::find($this->accountId);
        
        if ($account) {
            $service->recalculateBalance($account);
        }
    }
}

