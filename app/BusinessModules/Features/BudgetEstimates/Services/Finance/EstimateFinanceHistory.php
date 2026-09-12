<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EstimateFinanceHistory
{
    public function record(User $actor, Estimate $estimate, string $mutationId, int $revision, array $before, array $keys): void
    {
        $previous = array_column($before, null, 'key');
        $current = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->whereIn('key', $keys)
            ->get()->keyBy('key');
        $rows = [];
        foreach (array_unique(array_merge(array_keys($previous), $keys)) as $key) {
            $old = $previous[$key] ?? null;
            $new = $current->get($key)?->attributesToArray();
            if ($old === null && $new === null) {
                continue;
            }
            $rows[] = [
                'organization_id' => $estimate->organization_id, 'estimate_id' => $estimate->id,
                'allocation_key' => $key, 'condition_version' => $new['condition_version'] ?? ((int) $old['condition_version'] + 1),
                'finance_revision' => $revision, 'mutation_id' => $mutationId,
                'action' => $new === null ? 'deleted' : ($old === null ? 'created' : 'updated'),
                'before' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
                'after' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id, 'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('estimate_finance_condition_versions')->insert($chunk);
        }
    }

    public function forEstimate(Estimate $estimate, int $afterId = 0): array
    {
        $rows = DB::table('estimate_finance_condition_versions')->where('organization_id', $estimate->organization_id)
            ->where('estimate_id', $estimate->id)->where('id', '>', $afterId)->orderBy('id')->limit(101)->get();
        $hasMore = $rows->count() > 100;
        $page = $rows->take(100)->map(static function (object $row): array {
            $value = (array) $row;
            $value['before'] = $row->before === null ? null : json_decode($row->before, true, 512, JSON_THROW_ON_ERROR);
            $value['after'] = $row->after === null ? null : json_decode($row->after, true, 512, JSON_THROW_ON_ERROR);

            return $value;
        })->values()->all();

        return ['data' => $page, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? end($page)['id'] : null];
    }
}
