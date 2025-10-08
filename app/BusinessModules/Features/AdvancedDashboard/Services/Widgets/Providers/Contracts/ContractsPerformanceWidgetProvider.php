<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ContractsPerformanceWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_PERFORMANCE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $contracts = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->where('status', '!=', 'cancelled')
            ->get();

        $onTime = 0;
        $delayed = 0;

        foreach ($contracts as $contract) {
            if ($contract->end_date && Carbon::parse($contract->end_date)->isPast()) {
                if ($contract->status === 'completed') {
                    $onTime++;
                } else {
                    $delayed++;
                }
            }
        }

        $total = $onTime + $delayed;

        return [
            'total_completed' => $onTime,
            'total_delayed' => $delayed,
            'on_time_rate' => $total > 0 ? round(($onTime / $total) * 100, 2) : 0,
        ];
    }
}

