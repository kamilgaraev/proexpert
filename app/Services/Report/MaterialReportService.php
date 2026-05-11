<?php

namespace App\Services\Report;

use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Services\RateCoefficient\RateCoefficientService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaterialReportService
{
    public function __construct(private RateCoefficientService $rateCoefficientService)
    {
    }

    public function generateOfficialUsageReport(
        int $projectId,
        string $dateFrom,
        string $dateTo,
        ?int $reportNumber = null,
        array $filters = []
    ): array {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $project = Project::with(['organization'])->findOrFail($projectId);
        $periodFrom = Carbon::parse($dateFrom);
        $periodTo = Carbon::parse($dateTo);

        Log::info('Generating official material usage report', [
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'report_number' => $reportNumber,
        ]);

        $movementQuery = DB::table('warehouse_movements as wm')
            ->join('materials as m', 'm.id', '=', 'wm.material_id')
            ->leftJoin('measurement_units as mu', 'mu.id', '=', 'm.measurement_unit_id')
            ->where('wm.project_id', $projectId)
            ->where('wm.organization_id', $project->organization_id)
            ->whereBetween('wm.movement_date', [
                $periodFrom->copy()->startOfDay()->toDateTimeString(),
                $periodTo->copy()->endOfDay()->toDateTimeString(),
            ])
            ->select([
                'wm.id',
                'wm.material_id',
                'wm.movement_type',
                'wm.quantity',
                'wm.price',
                'wm.document_number',
                'wm.reason',
                'wm.movement_date',
                'm.name as material_name',
                DB::raw("COALESCE(mu.short_name, 'шт') as unit"),
            ]);

        $this->applyFiltersToWarehouseMovements($movementQuery, $filters);

        $totalCount = (clone $movementQuery)->count();
        if ($totalCount > 50000) {
            Log::warning('Official usage report: too many records', [
                'project_id' => $projectId,
                'count' => $totalCount,
            ]);

            throw new \Exception("Слишком много записей для отчета ({$totalCount}). Уточните период или фильтры.");
        }

        $movements = $movementQuery
            ->orderBy('wm.movement_date')
            ->orderBy('wm.id')
            ->get();

        $materialGroups = $this->groupWarehouseMovementsByMaterial($movements, $project->organization_id, $projectId);

        $header = [
            'report_number' => $reportNumber ?? 1,
            'report_date' => now()->format('d.m.Y'),
            'period_from' => $periodFrom->format('d.m.Y'),
            'period_to' => $periodTo->format('d.m.Y'),
            'project_name' => $project->name,
            'project_address' => $project->address,
        ];

        if ($materialGroups === []) {
            Log::warning('Official usage report: no material data', ['project_id' => $projectId]);

            return [
                'header' => $header,
                'organizations' => $this->organizationsBlock($project),
                'message' => 'Нет данных за выбранный период',
                'materials' => [],
                'summary' => $this->calculateSummary([]),
                'generated_at' => now(),
            ];
        }

        return [
            'header' => $header,
            'organizations' => $this->organizationsBlock($project),
            'materials' => $materialGroups,
            'summary' => $this->calculateSummary($materialGroups),
            'generated_at' => now(),
        ];
    }

    private function groupWarehouseMovementsByMaterial(Collection $movements, int $organizationId, int $projectId): array
    {
        $grouped = [];
        $coefficientsCache = [];

        foreach ($movements->groupBy('material_id') as $materialId => $materialMovements) {
            $firstMovement = $materialMovements->first();
            if (!$firstMovement) {
                continue;
            }

            $receivedMovements = $materialMovements->whereIn('movement_type', ['receipt', 'transfer_in', 'return']);
            $writeOffMovements = $materialMovements->whereIn('movement_type', ['write_off', 'transfer_out']);

            $receivedQuantity = (float) $receivedMovements->sum('quantity');
            $usedQuantity = (float) $writeOffMovements->sum('quantity');
            $normQuantity = $usedQuantity;

            $cacheKey = "{$materialId}_{$normQuantity}";
            if (!isset($coefficientsCache[$cacheKey])) {
                $coefficientsCache[$cacheKey] = $this->calculateNormWithCoefficients(
                    $organizationId,
                    $projectId,
                    (int) $materialId,
                    $normQuantity
                );
            }

            $coeffResult = $coefficientsCache[$cacheKey];
            $adjustedNormQuantity = (float) ($coeffResult['final'] ?? $normQuantity);
            $currentBalance = $receivedQuantity - $usedQuantity;
            $receivedDocs = $receivedMovements
                ->filter(fn ($movement): bool => !empty($movement->document_number))
                ->map(fn ($movement): string => '№'.$movement->document_number.' от '.Carbon::parse($movement->movement_date)->format('d.m.Y'))
                ->implode(', ');

            $grouped[] = [
                'work_name' => 'Общие материалы',
                'material_name' => $firstMovement->material_name,
                'unit' => $firstMovement->unit,
                'received_from_customer' => [
                    'volume' => $receivedQuantity,
                    'document' => $receivedDocs !== '' ? $receivedDocs : 'Документы не указаны',
                ],
                'usage' => [
                    'production_norm' => $adjustedNormQuantity,
                    'fact_used' => $usedQuantity,
                    'balance' => $currentBalance,
                    'for_next_month' => max(0, $currentBalance),
                ],
                'economy_overrun' => $adjustedNormQuantity - $usedQuantity,
                'economy_percentage' => $adjustedNormQuantity > 0
                    ? (($adjustedNormQuantity - $usedQuantity) / $adjustedNormQuantity) * 100
                    : 0,
                'coefficients_applied' => $coeffResult['applications'] ?? [],
            ];
        }

        return $grouped;
    }

    private function calculateNormWithCoefficients(
        int $organizationId,
        int $projectId,
        int $materialId,
        float $normQuantity
    ): array {
        try {
            return $this->rateCoefficientService->calculateAdjustedValueDetailed(
                $organizationId,
                $normQuantity,
                RateCoefficientAppliesToEnum::MATERIAL_NORMS->value,
                null,
                ['project_id' => $projectId, 'material_id' => $materialId]
            );
        } catch (\Exception $e) {
            Log::warning('Failed to calculate coefficients', [
                'material_id' => $materialId,
                'error' => $e->getMessage(),
            ]);

            return [
                'final' => $normQuantity,
                'applications' => [],
            ];
        }
    }

    private function calculateSummary(array $materials): array
    {
        $totalEconomy = collect($materials)->sum('economy_overrun');
        $totalNorm = collect($materials)->sum('usage.production_norm');

        return [
            'total_materials' => count($materials),
            'total_economy_overrun' => $totalEconomy,
            'economy_percentage' => $totalNorm > 0 ? ($totalEconomy / $totalNorm) * 100 : 0,
            'has_overruns' => $totalEconomy < 0,
            'has_economy' => $totalEconomy > 0,
        ];
    }

    private function organizationsBlock(Project $project): array
    {
        return [
            'contractor' => $project->organization->name,
            'contractor_director' => $this->getDirectorName($project->organization),
            'customer' => $project->customer_organization ?? $project->customer ?? 'Заказчик не указан',
            'customer_representative' => $project->customer_representative ?? 'Не указан',
            'contract_number' => $project->contract_number,
            'contract_date' => $project->contract_date?->format('d.m.Y'),
        ];
    }

    private function getDirectorName(Organization $organization): string
    {
        return 'Директор не указан';
    }

    private function applyFiltersToWarehouseMovements($query, array $filters): void
    {
        if (!empty($filters['material_id'])) {
            $query->where('wm.material_id', $filters['material_id']);
        }

        if (!empty($filters['material_name'])) {
            $query->where('m.name', 'like', '%'.$filters['material_name'].'%');
        }

        if (!empty($filters['operation_type'])) {
            $query->where('wm.movement_type', $filters['operation_type']);
        }

        if (!empty($filters['document_number'])) {
            $query->where('wm.document_number', 'like', '%'.$filters['document_number'].'%');
        }

        if (!empty($filters['user_id']) || !empty($filters['foreman_id'])) {
            $query->where('wm.user_id', $filters['user_id'] ?? $filters['foreman_id']);
        }

        if (!empty($filters['min_quantity'])) {
            $query->where('wm.quantity', '>=', $filters['min_quantity']);
        }

        if (!empty($filters['max_quantity'])) {
            $query->where('wm.quantity', '<=', $filters['max_quantity']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('wm.price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('wm.price', '<=', $filters['max_price']);
        }
    }
}
