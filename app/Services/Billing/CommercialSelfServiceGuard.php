<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialAccountStatus;
use App\Exceptions\Billing\CorporateSelfServiceMutationException;
use App\Models\OrganizationCommercialAccount;

final class CommercialSelfServiceGuard
{
    public function assertCanMutate(?OrganizationCommercialAccount $account): void
    {
        if ($account?->status === CommercialAccountStatus::Corporate) {
            throw new CorporateSelfServiceMutationException;
        }
    }
}
