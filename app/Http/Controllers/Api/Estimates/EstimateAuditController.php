<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Audit\EstimateAuditService;
use App\Models\Estimate;
use App\Models\EstimateSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EstimateAuditController extends Controller
{
    public function __construct(
        protected EstimateAuditService $auditService
    ) {}

    public function history(Request $request, int $estimateId): JsonResponse
    {
        $estimate = Estimate::findOrFail($estimateId);

        $filters = [
            'change_type' => $request->input('change_type'),
            'user_id' => $request->input('user_id'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'limit' => $request->input('limit', 100),
        ];

        $history = $this->auditService->getChangeHistory($estimate, $filters);

        return \App\Http\Responses\AdminResponse::fromPayload($history);
    }

    public function snapshots(Request $request, int $estimateId): JsonResponse
    {
        $estimate = Estimate::findOrFail($estimateId);
        $type = $request->input('type');

        $snapshots = $this->auditService->getSnapshots($estimate, $type);

        return \App\Http\Responses\AdminResponse::fromPayload($snapshots);
    }

    public function createSnapshot(Request $request, int $estimateId): JsonResponse
    {
        $request->validate([
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $estimate = Estimate::findOrFail($estimateId);

        $snapshot = $this->auditService->createSnapshot(
            $estimate,
            'manual',
            $request->input('label'),
            $request->input('description')
        );

        return \App\Http\Responses\AdminResponse::fromPayload($snapshot, 201);
    }

    public function compare(Request $request): JsonResponse
    {
        $request->validate([
            'estimate_id_1' => 'required|exists:estimates,id',
            'estimate_id_2' => 'required|exists:estimates,id',
        ]);

        $estimate1 = Estimate::findOrFail($request->input('estimate_id_1'));
        $estimate2 = Estimate::findOrFail($request->input('estimate_id_2'));

        $diff = $this->auditService->compareEstimates($estimate1, $estimate2);

        return \App\Http\Responses\AdminResponse::fromPayload($diff);
    }

    public function compareSnapshots(Request $request): JsonResponse
    {
        $request->validate([
            'snapshot_id_1' => 'required|exists:estimate_snapshots,id',
            'snapshot_id_2' => 'required|exists:estimate_snapshots,id',
        ]);

        $snapshot1 = EstimateSnapshot::findOrFail($request->input('snapshot_id_1'));
        $snapshot2 = EstimateSnapshot::findOrFail($request->input('snapshot_id_2'));

        $diff = $this->auditService->compareSnapshots($snapshot1, $snapshot2);

        return \App\Http\Responses\AdminResponse::fromPayload($diff);
    }

    public function restore(Request $request, int $estimateId, int $snapshotId): JsonResponse
    {
        $estimate = Estimate::findOrFail($estimateId);
        $snapshot = EstimateSnapshot::findOrFail($snapshotId);

        if ($snapshot->estimate_id !== $estimate->id) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => 'Снимок не принадлежит этой смете'], 400);
        }

        try {
            $estimate = $this->auditService->restoreFromSnapshot($estimate, $snapshot);

            return \App\Http\Responses\AdminResponse::fromPayload([
                'message' => 'Смета восстановлена из снимка',
                'estimate' => $estimate,
            ]);
        } catch (\RuntimeException $e) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => $e->getMessage()], 400);
        }
    }
}

