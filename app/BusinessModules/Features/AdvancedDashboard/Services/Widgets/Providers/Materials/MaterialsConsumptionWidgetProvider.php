<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MaterialsConsumptionWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_CONSUMPTION;
    }

    public function validateRequest(WidgetDataRequest $request): bool
    {
        return true;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        if (!$request->from || !$request->to) {
            $request = new WidgetDataRequest(
                widgetType: $request->widgetType,
                organizationId: $request->organizationId,
                userId: $request->userId,
                from: now()->startOfMonth(),
                to: now()->endOfMonth(),
                projectId: $request->projectId,
                contractId: $request->contractId,
                employeeId: $request->employeeId,
                filters: $request->filters,
                options: $request->options,
            );
        }

        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return ['consumption' => [], 'total_cost' => 0];
        }

        $consumption = DB::table('completed_work_materials')
            ->join('materials', 'completed_work_materials.material_id', '=', 'materials.id')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->select(
                'materials.id',
                'materials.name',
                DB::raw('SUM(completed_work_materials.quantity) as total_quantity'),
                DB::raw('SUM(completed_work_materials.total_amount) as total_cost')
            )
            ->groupBy('materials.id', 'materials.name')
            ->orderByDesc('total_cost')
            ->limit(20)
            ->get();

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'consumption' => $consumption->map(fn($c) => [
                'material_id' => $c->id,
                'material_name' => $c->name,
                'quantity' => (float)$c->total_quantity,
                'cost' => (float)$c->total_cost,
            ])->toArray(),
            'total_cost' => (float)$consumption->sum('total_cost'),
        ];
    }
}

