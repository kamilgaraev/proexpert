<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapOpeningBalanceSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceVersion;
use DateTimeInterface;

final readonly class PortfolioLiquidityAsOfSource
{
    public function __construct(private PaymentCalendarSourceService $calendar) {}

    public function read(
        int $organizationId,
        PaymentCalendarSourceFilters $filters,
        DateTimeInterface $asOf,
    ): array {
        $latestIds = PortfolioLiquiditySourceVersion::query()
            ->selectRaw('MAX(id)')
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->where('created_at', '<=', $asOf)
            ->groupBy('source_type', 'source_id');
        $versions = PortfolioLiquiditySourceVersion::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $latestIds)
            ->orderBy('id')
            ->get();
        if ($versions->isEmpty()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $calendarItems = [];
        $balances = [];
        foreach ($versions as $version) {
            $payload = $version->payload;
            if (! is_array($payload)) {
                continue;
            }
            if ($version->source_type === 'opening_balance') {
                $balance = $this->openingBalance($payload);
                if ($balance !== null
                    && $balance->balanceDate <= $filters->periodStart
                    && (! isset($balances[$balance->currency])
                        || $balance->balanceDate > $balances[$balance->currency]->balanceDate)) {
                    $balances[$balance->currency] = $balance;
                }

                continue;
            }
            $calendarItems[] = $this->calendarItem($payload);
        }

        return [
            'calendar' => $this->calendar->normalizeItems($calendarItems, $filters),
            'balances' => $balances,
            'versions' => $versions->map(static fn (PortfolioLiquiditySourceVersion $version): array => [
                'id' => (int) $version->getKey(),
                'source_type' => (string) $version->source_type,
                'source_id' => (string) $version->source_id,
                'source_version' => (string) $version->source_version,
                'occurred_at' => $version->occurred_at?->format(DateTimeInterface::ATOM),
                'created_at' => $version->created_at?->format(DateTimeInterface::ATOM),
                'effective_at' => $version->effective_at?->format(DateTimeInterface::ATOM),
                'source_hash' => (string) $version->source_hash,
            ])->all(),
        ];
    }

    private function calendarItem(array $payload): PaymentCalendarItem
    {
        return new PaymentCalendarItem(
            organizationId: (int) ($payload['organization_id'] ?? 0),
            date: (string) ($payload['date'] ?? ''),
            originalDate: is_string($payload['original_date'] ?? null) ? $payload['original_date'] : null,
            direction: (string) ($payload['direction'] ?? ''),
            bucket: (string) ($payload['bucket'] ?? ''),
            amount: (string) ($payload['amount'] ?? '0'),
            remainingAmount: (string) ($payload['remaining_amount'] ?? '0'),
            currency: (string) ($payload['currency'] ?? ''),
            probability: (float) ($payload['probability'] ?? 1),
            status: (string) ($payload['status'] ?? ''),
            sourceType: (string) ($payload['source_type'] ?? ''),
            sourceId: is_int($payload['source_id'] ?? null) || is_string($payload['source_id'] ?? null)
                ? $payload['source_id']
                : null,
            cashFlowKey: (string) ($payload['cash_flow_key'] ?? ''),
            projectId: isset($payload['project_id']) ? (int) $payload['project_id'] : null,
            counterpartyId: isset($payload['counterparty_id']) ? (int) $payload['counterparty_id'] : null,
            budgetArticleId: is_int($payload['budget_article_id'] ?? null) || is_string($payload['budget_article_id'] ?? null)
                ? $payload['budget_article_id']
                : null,
            responsibilityCenterId: is_int($payload['responsibility_center_id'] ?? null) || is_string($payload['responsibility_center_id'] ?? null)
                ? $payload['responsibility_center_id']
                : null,
            editable: false,
            drillDown: is_array($payload['drill_down'] ?? null) ? $payload['drill_down'] : [],
        );
    }

    private function openingBalance(array $payload): ?CashGapOpeningBalanceSnapshot
    {
        if (($payload['kind'] ?? null) !== 'opening_balance'
            || ! is_string($payload['balance_date'] ?? null)
            || ! is_string($payload['currency'] ?? null)
            || ! isset($payload['id'], $payload['organization_id'], $payload['amount'])) {
            return null;
        }

        return new CashGapOpeningBalanceSnapshot(
            id: (string) $payload['id'],
            organizationId: (int) $payload['organization_id'],
            balanceDate: $payload['balance_date'],
            currency: mb_strtoupper($payload['currency']),
            amount: (string) $payload['amount'],
            status: (string) ($payload['status'] ?? ''),
            approvedByUserId: null,
            approvedAt: is_string($payload['approved_at'] ?? null) ? $payload['approved_at'] : null,
        );
    }
}
