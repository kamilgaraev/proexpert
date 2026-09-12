<?php

declare(strict_types=1);

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateLaborHoursService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $calculator = new EstimateLaborHoursService;

        EstimateItem::query()
            ->whereHas('estimate', fn ($query) => $query->whereIn('status', ['draft', 'in_review']))
            ->where('item_type', 'work')
            ->where('is_manual', true)
            ->whereNull('parent_work_id')
            ->where(fn ($query) => $query->whereNull('labor_hours')->orWhere('labor_hours', 0))
            ->whereHas('childItems', fn ($query) => $query->where('item_type', 'labor'))
            ->chunkById(200, function ($items) use ($calculator): void {
                $estimateIds = [];
                foreach ($items as $item) {
                    if (! array_key_exists('raw_data', $item->metadata ?? [])) {
                        continue;
                    }
                    $hours = $calculator->calculate($item);
                    if ($hours > 0) {
                        $updated = DB::table('estimate_items')->where('id', $item->id)
                            ->where('estimate_id', $item->estimate_id)
                            ->where(fn ($query) => $query->whereNull('labor_hours')->orWhere('labor_hours', 0))
                            ->update(['labor_hours' => $hours]);
                        if ($updated > 0) {
                            $estimateIds[$item->estimate_id] = $item->estimate_id;
                        }
                    }
                }
                DB::table('estimates')->whereIn('id', $estimateIds)->update(['structure_cache_path' => null]);
                foreach (Estimate::query()->whereIn('id', $estimateIds)->get() as $estimate) {
                    app(EstimateCacheService::class)->invalidateEstimate($estimate);
                }
            });
    }

    public function down(): void {}
};
