<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MaterialsTopUsedWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_TOP_USED;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonth();
        $to = $request->to ?? Carbon::now();

        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return ['top_materials' => []];
        }

        $topMaterials = DB::table('completed_work_materials')
            ->join('materials', 'completed_work_materials.material_id', '=', 'materials.id')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->select(
                'materials.id',
                'materials.name',
                DB::raw('SUM(completed_work_materials.quantity) as total_quantity'),
                DB::raw('SUM(completed_work_materials.total_amount) as total_cost'),
                DB::raw('COUNT(DISTINCT completed_works.project_id) as projects_count')
            )
            ->groupBy('materials.id', 'materials.name')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'top_materials' => $topMaterials->map(fn($m) => [
                'material_id' => $m->id,
                'material_name' => $m->name,
                'quantity_used' => (float)$m->total_quantity,
                'total_cost' => (float)$m->total_cost,
                'projects_count' => $m->projects_count,
            ])->toArray(),
        ];
    }
}

