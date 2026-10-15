<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Filament\Support\FilamentPermission;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class BankBillingAccess
{
    public function organization(User $actor, int $organizationId, bool $manage = false): Organization
    {
        $organization = $actor->organizations()->where('organizations.id', $organizationId)
            ->wherePivot('is_active', true)->first();
        if (! $actor->is_active || $organization === null || ! $organization->is_active || ! app(AuthorizationService::class)->canCurrent(
            $actor, $manage ? 'billing.manage' : 'billing.view', ['organization_id' => $organizationId],
        )) {
            throw new AuthorizationException(trans_message('bank_billing.forbidden'));
        }

        return $organization;
    }

    public function operator(SystemAdmin $actor): void
    {
        if (! $actor->isActive() || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new AuthorizationException(trans_message('bank_billing.forbidden'));
        }
    }
}
