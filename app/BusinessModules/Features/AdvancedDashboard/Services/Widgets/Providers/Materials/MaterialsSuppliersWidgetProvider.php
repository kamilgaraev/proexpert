<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MaterialsSuppliersWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::MATERIALS_SUPPLIERS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonths(3);
        $to = $request->to ?? Carbon::now();

        $suppliers = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('material_receipts.receipt_date', [$from, $to])
            ->select(
                DB::raw('COALESCE(material_receipts.supplier, "Unknown") as supplier'),
                DB::raw('SUM(material_receipts.total_amount) as total_amount'),
                DB::raw('COUNT(*) as receipts_count')
            )
            ->groupBy('supplier')
            ->orderByDesc('total_amount')
            ->get();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'suppliers' => $suppliers->map(fn($s) => [
                'supplier_name' => $s->supplier,
                'total_amount' => (float)$s->total_amount,
                'receipts_count' => $s->receipts_count,
            ])->toArray(),
            'total_suppliers' => $suppliers->count(),
        ];
    }
}

