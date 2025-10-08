<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ContractsByContractorWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_BY_CONTRACTOR;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $contracts = DB::table('contracts')
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->where('contracts.organization_id', $request->organizationId)
            ->select(
                'contractors.id as contractor_id',
                'contractors.name as contractor_name',
                DB::raw('COUNT(contracts.id) as contracts_count'),
                DB::raw('SUM(contracts.total_amount) as total_value')
            )
            ->groupBy('contractors.id', 'contractors.name')
            ->orderByDesc('total_value')
            ->get();

        return [
            'by_contractor' => $contracts->map(fn($c) => [
                'contractor_id' => $c->contractor_id,
                'contractor_name' => $c->contractor_name,
                'contracts_count' => $c->contracts_count,
                'total_value' => (float)$c->total_value,
            ])->toArray(),
            'total_contractors' => $contracts->count(),
        ];
    }
}

