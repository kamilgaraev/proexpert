<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ContractsStatusWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_STATUS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $statuses = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->select('status', DB::raw('count(*) as count'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('status')
            ->get();

        $total = $statuses->sum('count');
        $data = [];

        foreach ($statuses as $status) {
            $data[] = [
                'status' => $status->status,
                'count' => $status->count,
                'percentage' => $total > 0 ? round(($status->count / $total) * 100, 2) : 0,
                'total_value' => (float)$status->total,
            ];
        }

        return ['by_status' => $data, 'total' => $total];
    }
}

