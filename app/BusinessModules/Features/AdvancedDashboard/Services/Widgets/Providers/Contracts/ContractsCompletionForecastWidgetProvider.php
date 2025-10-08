<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ContractsCompletionForecastWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_COMPLETION_FORECAST;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $contracts = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->whereNotNull('end_date')
            ->select('id', 'number', 'end_date', 'total_amount')
            ->get();

        $forecasts = [];

        foreach ($contracts as $contract) {
            $completedValue = DB::table('completed_works')
                ->where('contract_id', $contract->id)
                ->sum(DB::raw('quantity * price'));

            $progress = $contract->total_amount > 0 ? ($completedValue / $contract->total_amount) * 100 : 0;
            $endDate = Carbon::parse($contract->end_date);
            $daysRemaining = max(0, Carbon::now()->diffInDays($endDate, false));

            $forecasts[] = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'progress' => round($progress, 2),
                'planned_end_date' => $endDate->toIso8601String(),
                'forecasted_delay_days' => $progress < 50 && $daysRemaining < 30 ? 15 : 0,
            ];
        }

        return ['forecasts' => $forecasts];
    }
}

