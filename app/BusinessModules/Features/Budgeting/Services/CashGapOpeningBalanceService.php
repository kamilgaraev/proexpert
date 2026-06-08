<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapOpeningBalanceSnapshot;
use App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance;
use Illuminate\Database\Eloquent\Collection;

final class CashGapOpeningBalanceService
{
    public function latestApproved(int $organizationId, string $currency, string $periodStart): ?CashGapOpeningBalanceSnapshot
    {
        $balance = CashGapOpeningBalance::query()
            ->forOrganization($organizationId)
            ->approved()
            ->where('currency', mb_strtoupper($currency))
            ->whereDate('balance_date', '<=', $periodStart)
            ->orderByDesc('balance_date')
            ->orderByDesc('id')
            ->first();

        return $balance instanceof CashGapOpeningBalance ? $this->snapshot($balance) : null;
    }

    /**
     * @param list<string>|null $currencies
     * @return array<string, CashGapOpeningBalanceSnapshot>
     */
    public function latestApprovedByCurrency(int $organizationId, string $periodStart, ?array $currencies = null): array
    {
        $normalizedCurrencies = $currencies === null
            ? null
            : array_values(array_unique(array_map(static fn (string $currency): string => mb_strtoupper($currency), $currencies)));

        $balances = CashGapOpeningBalance::query()
            ->forOrganization($organizationId)
            ->approved()
            ->whereDate('balance_date', '<=', $periodStart)
            ->when($normalizedCurrencies !== null && $normalizedCurrencies !== [], function ($query) use ($normalizedCurrencies): void {
                $query->whereIn('currency', $normalizedCurrencies);
            })
            ->orderByDesc('balance_date')
            ->orderByDesc('id')
            ->get();

        return $this->firstByCurrency($balances);
    }

    private function snapshot(CashGapOpeningBalance $balance): CashGapOpeningBalanceSnapshot
    {
        return new CashGapOpeningBalanceSnapshot(
            id: (int) $balance->getKey(),
            organizationId: (int) $balance->organization_id,
            balanceDate: $balance->balance_date?->format('Y-m-d') ?? '',
            currency: mb_strtoupper((string) $balance->currency),
            amount: round((float) $balance->amount, 2),
            status: (string) $balance->status,
            approvedByUserId: $balance->approved_by_user_id !== null ? (int) $balance->approved_by_user_id : null,
            approvedAt: $balance->approved_at?->toIso8601String(),
        );
    }

    /**
     * @param Collection<int, CashGapOpeningBalance> $balances
     * @return array<string, CashGapOpeningBalanceSnapshot>
     */
    private function firstByCurrency(Collection $balances): array
    {
        $snapshots = [];

        foreach ($balances as $balance) {
            $currency = mb_strtoupper((string) $balance->currency);

            if (array_key_exists($currency, $snapshots)) {
                continue;
            }

            $snapshots[$currency] = $this->snapshot($balance);
        }

        return $snapshots;
    }
}
