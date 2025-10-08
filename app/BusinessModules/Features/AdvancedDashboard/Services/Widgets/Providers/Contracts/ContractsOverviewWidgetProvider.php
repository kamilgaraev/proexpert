<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ContractsOverviewWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_OVERVIEW;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $total = DB::table('contracts')->where('organization_id', $request->organizationId)->count();
        $active = DB::table('contracts')->where('organization_id', $request->organizationId)->where('status', 'active')->count();
        $totalValue = DB::table('contracts')->where('organization_id', $request->organizationId)->sum('total_amount');

        return [
            'total_contracts' => $total,
            'active_contracts' => $active,
            'total_value' => (float)$totalValue,
            'average_value' => $total > 0 ? round($totalValue / $total, 2) : 0,
        ];
    }
}

