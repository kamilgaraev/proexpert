<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapOpeningBalanceSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceGap;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class PortfolioLiquidityAsOfSource
{
    public function __construct(private PaymentCalendarSourceService $calendar) {}

    public function read(
        int $organizationId,
        PaymentCalendarSourceFilters $filters,
        DateTimeInterface $asOf,
        ?DateTimeInterface $ingestedThrough = null,
    ): array {
        $ingestedThrough = $ingestedThrough === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($ingestedThrough->format(DateTimeInterface::ATOM));
        $versions = PortfolioLiquiditySourceVersion::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->where('recorded_at', '<=', $ingestedThrough)
            ->whereNotExists(static function (QueryBuilder $newer) use ($asOf, $ingestedThrough): void {
                $table = 'budgeting_portfolio_liquidity_source_versions';
                $newer
                    ->selectRaw('1')
                    ->from($table.' as newer_source')
                    ->whereColumn('newer_source.organization_id', $table.'.organization_id')
                    ->whereColumn('newer_source.source_type', $table.'.source_type')
                    ->whereColumn('newer_source.source_id', $table.'.source_id')
                    ->where('newer_source.occurred_at', '<=', $asOf)
                    ->where('newer_source.recorded_at', '<=', $ingestedThrough)
                    ->where(static fn (QueryBuilder $order): QueryBuilder => $order
                        ->whereColumn('newer_source.occurred_at', '>', $table.'.occurred_at')
                        ->orWhere(static fn (QueryBuilder $sameOccurrence): QueryBuilder => $sameOccurrence
                            ->whereColumn('newer_source.occurred_at', $table.'.occurred_at')
                            ->where(static fn (QueryBuilder $ingestionOrder): QueryBuilder => $ingestionOrder
                                ->whereColumn('newer_source.recorded_at', '>', $table.'.recorded_at')
                                ->orWhere(static fn (QueryBuilder $sameIngestion): QueryBuilder => $sameIngestion
                                    ->whereColumn('newer_source.recorded_at', $table.'.recorded_at')
                                    ->whereColumn('newer_source.id', '>', $table.'.id')))));
            })
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->get();
        $gaps = PortfolioLiquiditySourceGap::query()
            ->where('organization_id', $organizationId)
            ->where('business_effective_at', '<=', $asOf)
            ->where('recorded_at', '<=', $ingestedThrough)
            ->where(static fn ($query) => $query
                ->whereNull('resolved_at')
                ->orWhere('resolved_at', '>', $ingestedThrough))
            ->orderBy('id')
            ->get()
            ->map(static fn (PortfolioLiquiditySourceGap $gap): array => [
                'code' => 'source_projection_gap',
                'source_type' => (string) $gap->source_type,
                'source_id' => (string) $gap->source_id,
                'missing_fields' => is_array($gap->missing_fields) ? $gap->missing_fields : [],
                'business_effective_at' => $gap->business_effective_at?->format(DateTimeInterface::ATOM),
                'recorded_at' => $gap->recorded_at?->format(DateTimeInterface::ATOM),
            ])
            ->all();
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
            if ($version->source_type === 'budget_amount'
                && ! in_array($payload['status'] ?? null, ['approved', 'active'], true)) {
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
                'recorded_at' => $version->recorded_at?->format(DateTimeInterface::ATOM),
                'effective_at' => $version->effective_at?->format(DateTimeInterface::ATOM),
                'history_complete' => (bool) $version->history_complete,
                'source_hash' => (string) $version->source_hash,
            ])->all(),
            'gaps' => $gaps,
            'ingestion_watermark' => $ingestedThrough->format(DateTimeInterface::ATOM),
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
            probability: is_string($payload['probability'] ?? null)
                ? $payload['probability']
                : (string) ($payload['probability'] ?? '1'),
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
