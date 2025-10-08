<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MaterialsByProjectWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_BY_PROJECT;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonth();
        $to = $request->to ?? Carbon::now();

        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return ['by_project' => []];
        }

        $byProject = DB::table('completed_work_materials')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->select(
                'projects.id as project_id',
                'projects.name as project_name',
                DB::raw('SUM(completed_work_materials.total_amount) as total_cost'),
                DB::raw('COUNT(DISTINCT completed_work_materials.material_id) as materials_count')
            )
            ->groupBy('projects.id', 'projects.name')
            ->orderByDesc('total_cost')
            ->get();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'by_project' => $byProject->map(fn($p) => [
                'project_id' => $p->project_id,
                'project_name' => $p->project_name,
                'total_cost' => (float)$p->total_cost,
                'materials_count' => $p->materials_count,
            ])->toArray(),
        ];
    }
}

