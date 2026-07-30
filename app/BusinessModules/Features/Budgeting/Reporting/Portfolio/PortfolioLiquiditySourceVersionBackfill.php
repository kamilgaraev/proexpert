<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class PortfolioLiquiditySourceVersionBackfill
{
    private const SOURCES = [
        'payment_document' => PaymentDocument::class,
        'payment_schedule' => PaymentSchedule::class,
        'payment_transaction' => PaymentTransaction::class,
        'budget_limit_reservation' => BudgetLimitReservation::class,
        'budget_amount' => BudgetAmount::class,
        'opening_balance' => CashGapOpeningBalance::class,
    ];

    public function __construct(private PortfolioLiquiditySourceVersionRecorder $recorder) {}

    public function supportedSourceTypes(): array
    {
        return array_keys(self::SOURCES);
    }

    public function projectSourceSlice(
        string $sourceType,
        int $organizationId,
        int $afterId = 0,
        int $limit = 500,
    ): array {
        $model = self::SOURCES[$sourceType] ?? null;
        if (! is_string($model) || $organizationId < 1) {
            throw new InvalidArgumentException('portfolio_liquidity_backfill_source_invalid');
        }

        $query = $model::query();
        $idColumn = $query->getModel()->qualifyColumn('id');
        $rows = $this->scope($query, $sourceType, $organizationId)
            ->where($idColumn, '>', $afterId)
            ->orderBy($idColumn)
            ->limit($this->limit($limit))
            ->get();
        $versionIds = [];
        $gapSourceIds = [];

        foreach ($rows as $row) {
            $version = $this->recorder->record(
                $row,
                $row->getAttribute('updated_at') ?? $row->getAttribute('created_at'),
            );
            if ($version === null) {
                $gapSourceIds[] = (string) $row->getKey();
            } else {
                $versionIds[] = (int) $version->getKey();
            }
        }

        $sourceIds = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return [
            'source_type' => $sourceType,
            'source_ids' => $sourceIds,
            'version_ids' => $versionIds,
            'gap_source_ids' => $gapSourceIds,
            'next_cursor' => $sourceIds === [] ? null : max($sourceIds),
            'has_more' => count($sourceIds) === $this->limit($limit),
        ];
    }

    private function scope(Builder $query, string $sourceType, int $organizationId): Builder
    {
        if ($sourceType === 'budget_amount') {
            return $query->whereHas(
                'line.version',
                static fn (Builder $version): Builder => $version->where('organization_id', $organizationId),
            );
        }

        if (in_array($sourceType, ['payment_schedule', 'budget_limit_reservation'], true)) {
            return $query->whereHas(
                'paymentDocument',
                static fn (Builder $document): Builder => $document->where('organization_id', $organizationId),
            );
        }

        return $query->where('organization_id', $organizationId);
    }

    private function limit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }
}
