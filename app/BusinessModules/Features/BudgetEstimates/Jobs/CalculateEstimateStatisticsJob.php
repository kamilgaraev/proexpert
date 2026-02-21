<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Jobs;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateEstimateStatisticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes should be more than enough for pure SQL

    public readonly int $estimateId;

    public function __construct(int $estimateId)
    {
        $this->estimateId = $estimateId;
    }

    public function handle(): void
    {
        try {
            DB::transaction(function () {
                // Lock the estimate for update to prevent race conditions during save
                $estimate = Estimate::lockForUpdate()->find($this->estimateId);
                
                if (!$estimate) {
                    return;
                }

                $itemsCount = EstimateItem::where('estimate_id', $this->estimateId)->count();
                $sectionsCount = EstimateSection::where('estimate_id', $this->estimateId)->count();

                $statistics = $estimate->statistics ?? [];
                $statistics['items_count'] = $itemsCount;
                $statistics['sections_count'] = $sectionsCount;
                
                // Example of additional statistics that can be added in the future:
                // $totals = EstimateItem::where('estimate_id', $this->estimateId)
                //     ->selectRaw('SUM(total_direct_costs) as direct_costs')
                //     ->first();
                // $statistics['sum_direct_costs'] = $totals?->direct_costs ?? 0;

                $estimate->statistics = $statistics;
                $estimate->save();

                Log::info('Estimate statistics calculated', [
                    'estimate_id' => $this->estimateId,
                    'items_count' => $itemsCount,
                    'sections_count' => $sectionsCount
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to calculate estimate statistics', [
                'estimate_id' => $this->estimateId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
