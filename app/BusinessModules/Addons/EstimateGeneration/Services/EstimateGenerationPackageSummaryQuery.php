<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use Illuminate\Database\Eloquent\Builder;

final class EstimateGenerationPackageSummaryQuery
{
    /**
     * @param  Builder<\App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage>  $query
     * @return array<string, int>
     */
    public function summarize(Builder $query): array
    {
        $row = (clone $query)->reorder()->selectRaw($this->aggregateSql())->toBase()->first();

        return $this->fromRow((array) $row);
    }

    /** @return array<string, int> */
    public function fromRow(array $row): array
    {
        $keys = [
            'total', 'planned', 'processing', 'ready', 'review_required', 'approved', 'blocked', 'failed',
            'priced_items_count', 'quantity_review_items_count', 'operation_items_count', 'hidden_service_items_count',
        ];

        return array_combine($keys, array_map(static fn (string $key): int => (int) ($row[$key] ?? 0), $keys));
    }

    public function aggregateSql(): string
    {
        $hidden = "GREATEST(COALESCE((totals->>'operation_items_count')::int, 0), 0)"
            ." + GREATEST(COALESCE((totals->>'review_notes_count')::int, 0), 0)";
        $quantity = "GREATEST(COALESCE((totals->>'quantity_review_items_count')::int, 0), 0)";
        $visible = "GREATEST(COALESCE((totals->>'total_items_count')::int, (totals->>'items_count')::int, actual_items_count, 0) - ({$hidden}), 0)";
        $priced = "GREATEST(COALESCE((totals->>'priced_items_count')::int, ({$visible}) - ({$quantity})), 0)";

        return <<<SQL
COUNT(*) AS total,
COUNT(*) FILTER (WHERE status = 'planned') AS planned,
COUNT(*) FILTER (WHERE status IN ('queued', 'processing', 'generating')) AS processing,
COUNT(*) FILTER (WHERE status IN ('ready_for_review', 'approved')) AS ready,
COUNT(*) FILTER (WHERE status = 'review_required') AS review_required,
COUNT(*) FILTER (WHERE status = 'approved') AS approved,
COUNT(*) FILTER (WHERE status = 'blocked') AS blocked,
COUNT(*) FILTER (WHERE status = 'failed') AS failed,
COALESCE(SUM({$priced}), 0) AS priced_items_count,
COALESCE(SUM({$quantity}), 0) AS quantity_review_items_count,
0 AS operation_items_count,
COALESCE(SUM({$hidden}), 0) AS hidden_service_items_count
SQL;
    }
}
