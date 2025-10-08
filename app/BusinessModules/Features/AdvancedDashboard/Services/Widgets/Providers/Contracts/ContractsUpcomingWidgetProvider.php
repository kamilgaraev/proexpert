<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ContractsUpcomingWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_UPCOMING;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $upcoming = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->where('end_date', '>=', Carbon::now())
            ->where('end_date', '<=', Carbon::now()->addDays(30))
            ->select('id', 'number', 'end_date', 'total_amount', 'status')
            ->orderBy('end_date')
            ->get();

        return [
            'upcoming_deadlines' => $upcoming->map(fn($c) => [
                'contract_id' => $c->id,
                'contract_number' => $c->number,
                'end_date' => $c->end_date,
                'days_remaining' => Carbon::parse($c->end_date)->diffInDays(Carbon::now()),
                'value' => (float)$c->total_amount,
            ])->toArray(),
            'total_upcoming' => $upcoming->count(),
        ];
    }
}

