<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DeadlineRiskWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::DEADLINE_RISK;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $now = Carbon::now();
        $contracts = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $now)
            ->select('id', 'number', 'end_date')
            ->get();

        $risks = $contracts->map(function($c) use ($now) {
            $endDate = Carbon::parse($c->end_date);
            $daysRemaining = $now->diffInDays($endDate, false);
            
            return [
                'contract_id' => $c->id,
                'contract_number' => $c->number,
                'end_date' => $endDate->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'risk_level' => $daysRemaining < 7 ? 'high' : ($daysRemaining < 30 ? 'medium' : 'low'),
            ];
        })->filter(fn($r) => $r['risk_level'] !== 'low')->values()->toArray();

        return ['deadline_risks' => $risks];
    }
}

