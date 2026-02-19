<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Support\Facades\Log;

class EstimateVersioningService
{
    public function createSnapshot(Estimate $estimate, int $userId, ?string $label = null, ?string $comment = null): EstimateVersion
    {
        $lastVersion = EstimateVersion::where('estimate_id', $estimate->id)
            ->max('version_number') ?? 0;

        $snapshot = $this->buildSnapshot($estimate);

        $version = EstimateVersion::create([
            'estimate_id'        => $estimate->id,
            'created_by_user_id' => $userId,
            'version_number'     => $lastVersion + 1,
            'label'              => $label ?? "Версия " . ($lastVersion + 1),
            'comment'            => $comment,
            'snapshot'           => $snapshot,
            'total_amount'       => (float)$estimate->total_amount,
            'total_amount_with_vat' => (float)$estimate->total_amount_with_vat,
            'total_direct_costs' => (float)$estimate->total_direct_costs,
        ]);

        Log::info("[Versioning] Snapshot v{$version->version_number} created for estimate {$estimate->id}");

        return $version;
    }

    public function listVersions(int $estimateId): array
    {
        return EstimateVersion::where('estimate_id', $estimateId)
            ->with('createdBy:id,name')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn($v) => [
                'id'                    => $v->id,
                'version_number'        => $v->version_number,
                'label'                 => $v->label,
                'comment'               => $v->comment,
                'total_amount'          => $v->total_amount,
                'total_amount_with_vat' => $v->total_amount_with_vat,
                'total_direct_costs'    => $v->total_direct_costs,
                'created_by'            => $v->createdBy?->name,
                'created_at'            => $v->created_at->toIso8601String(),
            ])
            ->all();
    }

    public function getVersion(int $versionId): ?array
    {
        $version = EstimateVersion::with('createdBy:id,name')->find($versionId);

        if (!$version) {
            return null;
        }

        return [
            'id'             => $version->id,
            'version_number' => $version->version_number,
            'label'          => $version->label,
            'comment'        => $version->comment,
            'snapshot'       => $version->snapshot,
            'totals'         => [
                'total_amount'          => $version->total_amount,
                'total_amount_with_vat' => $version->total_amount_with_vat,
                'total_direct_costs'    => $version->total_direct_costs,
            ],
            'created_by'     => $version->createdBy?->name,
            'created_at'     => $version->created_at->toIso8601String(),
        ];
    }

    private function buildSnapshot(Estimate $estimate): array
    {
        $estimate->loadMissing(['sections.items.measurementUnit']);

        $sections = $estimate->sections->map(fn($s) => [
            'id'            => $s->id,
            'number'        => $s->section_number,
            'name'          => $s->name,
            'total'         => $s->total_amount ?? 0,
            'items'         => $s->items->map(fn($i) => [
                'id'                => $i->id,
                'name'              => $i->name,
                'code'              => $i->normative_rate_code,
                'unit'              => $i->measurementUnit?->name,
                'quantity'          => $i->quantity,
                'unit_price'        => $i->unit_price,
                'base_unit_price'   => $i->base_unit_price,
                'price_index'       => $i->price_index,
                'total_amount'      => $i->total_amount,
                'current_total'     => $i->current_total_amount,
            ])->values()->all(),
        ])->values()->all();

        return [
            'estimate_id'         => $estimate->id,
            'name'                => $estimate->name,
            'calculation_method'  => $estimate->calculation_method,
            'sections'            => $sections,
            'totals'              => [
                'total_amount'          => $estimate->total_amount,
                'total_direct_costs'    => $estimate->total_direct_costs,
                'total_overhead_costs'  => $estimate->total_overhead_costs,
                'total_amount_with_vat' => $estimate->total_amount_with_vat,
            ],
            'snapshotted_at'      => now()->toIso8601String(),
        ];
    }
}
