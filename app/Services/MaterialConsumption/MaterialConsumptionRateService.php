<?php

declare(strict_types=1);

namespace App\Services\MaterialConsumption;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\Exceptions\BusinessLogicException;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\MaterialConsumptionRate;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\WorkType;
use App\Models\WorkTypeMaterial;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class MaterialConsumptionRateService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, array $data, int $userId): MaterialConsumptionRate
    {
        return DB::transaction(function () use ($organizationId, $data, $userId): MaterialConsumptionRate {
            $payload = $this->normalizedPayload($organizationId, $data);
            $payloadHash = $this->payloadHash($payload);

            $existing = MaterialConsumptionRate::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new BusinessLogicException(trans_message('material_consumption.rate_idempotency_conflict'), 409);
                }

                return $existing;
            }

            $versionNumber = $this->nextVersionNumber(
                $organizationId,
                $payload['material_id'],
                $payload['work_type_id'],
                $payload['estimate_item_id'],
            );

            try {
                return MaterialConsumptionRate::query()->create([
                    ...$payload,
                    'organization_id' => $organizationId,
                    'version_number' => $versionNumber,
                    'status' => MaterialConsumptionRate::STATUS_DRAFT,
                    'payload_hash' => $payloadHash,
                    'created_by_user_id' => $userId,
                    'approved_by_user_id' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessLogicException(trans_message('material_consumption.rate_identity_conflict'), 409);
            }
        });
    }

    public function approve(MaterialConsumptionRate $rate, int $userId): MaterialConsumptionRate
    {
        return DB::transaction(function () use ($rate, $userId): MaterialConsumptionRate {
            $locked = MaterialConsumptionRate::query()->lockForUpdate()->findOrFail($rate->id);
            if ($locked->status === MaterialConsumptionRate::STATUS_APPROVED) {
                return $locked;
            }
            if ($locked->status !== MaterialConsumptionRate::STATUS_DRAFT) {
                throw new BusinessLogicException(trans_message('material_consumption.rate_cannot_approve'), 422);
            }

            $locked->forceFill([
                'status' => MaterialConsumptionRate::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    public function findApprovedForWork(
        int $organizationId,
        int $materialId,
        int $workTypeId,
        ?int $estimateItemId,
        string $asOfDate,
    ): ?MaterialConsumptionRate {
        $query = MaterialConsumptionRate::query()
            ->where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('work_type_id', $workTypeId)
            ->where('status', MaterialConsumptionRate::STATUS_APPROVED)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($builder) use ($asOfDate): void {
                $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOfDate);
            });

        if ($estimateItemId !== null) {
            $query->where(function ($builder) use ($estimateItemId): void {
                $builder->where('estimate_item_id', $estimateItemId)->orWhereNull('estimate_item_id');
            })->orderByRaw('CASE WHEN estimate_item_id IS NULL THEN 1 ELSE 0 END');
        } else {
            $query->whereNull('estimate_item_id');
        }

        return $query->orderByDesc('version_number')->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedPayload(int $organizationId, array $data): array
    {
        $materialId = (int) $data['material_id'];
        $workTypeId = (int) $data['work_type_id'];
        $workUnitId = (int) $data['work_unit_id'];
        $materialUnitId = (int) $data['material_unit_id'];
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $estimateItemId = isset($data['estimate_item_id']) ? (int) $data['estimate_item_id'] : null;
        $basisDocumentId = isset($data['basis_document_id']) ? (int) $data['basis_document_id'] : null;
        $workTypeMaterialId = isset($data['work_type_material_id']) ? (int) $data['work_type_material_id'] : null;

        $material = Material::query()->whereKey($materialId)->where('organization_id', $organizationId)->first();
        $workType = WorkType::query()->whereKey($workTypeId)->where('organization_id', $organizationId)->first();
        $workUnit = MeasurementUnit::query()->whereKey($workUnitId)->where('organization_id', $organizationId)->first();
        $materialUnit = MeasurementUnit::query()->whereKey($materialUnitId)->where('organization_id', $organizationId)->first();
        if ($material === null || $workType === null || $workUnit === null || $materialUnit === null) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_scope_invalid'), 422);
        }

        if ($projectId !== null && ! Project::query()->whereKey($projectId)->where('organization_id', $organizationId)->exists()) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_project_invalid'), 422);
        }
        if ($estimateItemId !== null && ! EstimateItem::query()->whereKey($estimateItemId)->exists()) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_scope_invalid'), 422);
        }
        if ($basisDocumentId !== null
            && ! ExecutiveDocument::query()->whereKey($basisDocumentId)->where('organization_id', $organizationId)->exists()) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_scope_invalid'), 422);
        }
        if ($workTypeMaterialId !== null
            && ! WorkTypeMaterial::query()
                ->whereKey($workTypeMaterialId)
                ->where('organization_id', $organizationId)
                ->where('work_type_id', $workTypeId)
                ->where('material_id', $materialId)
                ->exists()) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_scope_invalid'), 422);
        }

        $basisKind = (string) ($data['basis_kind'] ?? '');
        $basisText = trim((string) ($data['basis_text'] ?? ''));
        if (! in_array($basisKind, [
            MaterialConsumptionRate::BASIS_GESN,
            MaterialConsumptionRate::BASIS_PROJECT,
            MaterialConsumptionRate::BASIS_TECH_CARD,
        ], true) || $basisText === '' || mb_strlen($basisText) > 4000) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_basis_required'), 422);
        }

        $quantity = MaterialConsumptionQuantity::of(
            (string) $data['quantity_per_work_unit'],
            'material_consumption.rate_quantity_invalid',
        );
        if (! $quantity->isPositive()) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_quantity_invalid'), 422);
        }

        $effectiveFrom = (string) $data['effective_from'];
        $effectiveTo = isset($data['effective_to']) ? (string) $data['effective_to'] : null;

        return [
            'project_id' => $projectId,
            'material_id' => $materialId,
            'work_type_id' => $workTypeId,
            'estimate_item_id' => $estimateItemId,
            'work_unit_id' => $workUnitId,
            'material_unit_id' => $materialUnitId,
            'quantity_per_work_unit' => MaterialConsumptionQuantity::format($quantity),
            'basis_kind' => $basisKind,
            'basis_text' => $basisText,
            'basis_document_id' => $basisDocumentId,
            'work_type_material_id' => $workTypeMaterialId,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'idempotency_key' => (string) $data['idempotency_key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function nextVersionNumber(int $organizationId, int $materialId, int $workTypeId, ?int $estimateItemId): int
    {
        $query = MaterialConsumptionRate::query()
            ->where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('work_type_id', $workTypeId);
        if ($estimateItemId === null) {
            $query->whereNull('estimate_item_id');
        } else {
            $query->where('estimate_item_id', $estimateItemId);
        }

        return (int) $query->max('version_number') + 1;
    }
}
