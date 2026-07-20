<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Contract;

/**
 * Observer для автоматической синхронизации контракта с Event Sourcing
 */
class ContractObserver
{
    public function retrieved(Contract $contract): void {}
}
