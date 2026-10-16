<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditWallet;
use Illuminate\Database\Eloquent\Builder;

final class ReadEstimatorCreditBalance
{
    /** @return array{available_minor: int, reserved_minor: int, reconciliation_held_minor: int} */
    public function handle(int $organizationId): array
    {
        $wallet = AICreditWallet::query()->where('organization_id', $organizationId)->first();
        if ($wallet === null) {
            return ['available_minor' => 0, 'reserved_minor' => 0, 'reconciliation_held_minor' => 0];
        }

        $at = now();
        $lots = AICreditLot::query()->where('organization_id', $organizationId)->where('remaining_minor', '>', 0);
        $expired = (int) (clone $lots)->whereNotNull('expires_at')->where('expires_at', '<=', $at)->sum('remaining_minor');
        $held = (int) (clone $lots)->where('source', 'conversion')
            ->where('metadata->'.AICreditLot::LEGACY_REFUND_HOLD, true)
            ->where(static fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            ->sum('remaining_minor');
        $reserved = (int) $wallet->reserved_minor;

        return [
            'available_minor' => max(0, (int) $wallet->balance_minor - $expired - $reserved - $held),
            'reserved_minor' => $reserved,
            'reconciliation_held_minor' => $held,
        ];
    }
}
