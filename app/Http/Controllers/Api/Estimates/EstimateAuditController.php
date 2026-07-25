<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Audit\EstimateAuditService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateSnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class EstimateAuditController extends Controller
{
    public function __construct(private readonly EstimateAuditService $auditService)
    {
    }

    public function history(Request $request, int $estimateId): JsonResponse
    {
        $validated = $request->validate([
            'change_type' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $estimate = $this->estimateForRequest($request, $estimateId);

            return AdminResponse::success($this->auditService->getChangeHistory($estimate, $validated));
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.history.failed', $exception);
        }
    }

    public function snapshots(Request $request, int $estimateId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $estimate = $this->estimateForRequest($request, $estimateId);

            return AdminResponse::success($this->auditService->getSnapshots($estimate, $validated['type'] ?? null));
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.snapshots.failed', $exception);
        }
    }

    public function createSnapshot(Request $request, int $estimateId): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $estimate = $this->estimateForRequest($request, $estimateId);
            $snapshot = $this->auditService->createSnapshot(
                $estimate,
                'manual',
                $validated['label'] ?? null,
                $validated['description'] ?? null,
            );

            return AdminResponse::success($snapshot, null, 201);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.snapshot_create.failed', $exception);
        }
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'estimate_id_1' => ['required', 'integer', 'min:1'],
            'estimate_id_2' => ['required', 'integer', 'min:1', 'different:estimate_id_1'],
        ]);

        try {
            $estimate1 = $this->estimateForRequest($request, (int) $validated['estimate_id_1']);
            $estimate2 = $this->estimateForRequest($request, (int) $validated['estimate_id_2']);

            return AdminResponse::success($this->auditService->compareEstimates($estimate1, $estimate2));
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.compare.failed', $exception);
        }
    }

    public function compareSnapshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'snapshot_id_1' => ['required', 'integer', 'min:1'],
            'snapshot_id_2' => ['required', 'integer', 'min:1', 'different:snapshot_id_1'],
        ]);

        try {
            $organizationId = $this->organizationId($request);
            $snapshot1 = $this->snapshotForOrganization((int) $validated['snapshot_id_1'], $organizationId);
            $snapshot2 = $this->snapshotForOrganization((int) $validated['snapshot_id_2'], $organizationId);

            return AdminResponse::success($this->auditService->compareSnapshots($snapshot1, $snapshot2));
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.compare_snapshots.failed', $exception);
        }
    }

    public function restore(Request $request, int $estimateId, int $snapshotId): JsonResponse
    {
        try {
            $estimate = $this->estimateForRequest($request, $estimateId);
            $snapshot = EstimateSnapshot::query()
                ->whereKey($snapshotId)
                ->where('estimate_id', $estimate->id)
                ->firstOrFail();
            $restoredEstimate = $this->auditService->restoreFromSnapshot($estimate, $snapshot);

            return AdminResponse::success(['estimate' => $restoredEstimate]);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (RuntimeException) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422);
        } catch (Throwable $exception) {
            return $this->serverError('estimate_audit.restore.failed', $exception);
        }
    }

    private function estimateForRequest(Request $request, int $estimateId): Estimate
    {
        return Estimate::query()
            ->whereKey($estimateId)
            ->where('organization_id', $this->organizationId($request))
            ->firstOrFail();
    }

    private function snapshotForOrganization(int $snapshotId, int $organizationId): EstimateSnapshot
    {
        return EstimateSnapshot::query()
            ->whereKey($snapshotId)
            ->whereHas('estimate', static fn ($query) => $query->where('organization_id', $organizationId))
            ->firstOrFail();
    }

    private function organizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (! is_int($organizationId) && ! ctype_digit((string) $organizationId)) {
            throw (new ModelNotFoundException())->setModel(Estimate::class);
        }

        return (int) $organizationId;
    }

    private function notFound(): JsonResponse
    {
        return AdminResponse::error(trans_message('errors.resource_not_found'), 404);
    }

    private function serverError(string $event, Throwable $exception): JsonResponse
    {
        Log::error($event, ['exception_class' => $exception::class]);

        return AdminResponse::error(trans_message('errors.server_error'), 500);
    }
}
