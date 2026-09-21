<?php

declare(strict_types=1);

namespace App\Services\MaterialConsumption;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Material;
use App\Models\MaterialConsumptionFact;
use App\Models\MeasurementUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class MaterialConsumptionFactService
{
    public function __construct(
        private readonly MaterialConsumptionRateService $rateService,
        private readonly MaterialConsumptionUnitConversion $unitConversion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(int $organizationId, array $data, int $userId): MaterialConsumptionFact
    {
        return DB::transaction(function () use ($organizationId, $data, $userId): MaterialConsumptionFact {
            $work = $this->lockedWork($organizationId, (int) $data['completed_work_id']);
            $material = Material::query()
                ->whereKey((int) $data['material_id'])
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if ($material === null) {
                throw new BusinessLogicException(trans_message('material_consumption.fact_material_invalid'), 422);
            }

            $kind = (string) ($data['kind'] ?? MaterialConsumptionFact::KIND_CONSUMPTION);
            if (! in_array($kind, [MaterialConsumptionFact::KIND_CONSUMPTION, MaterialConsumptionFact::KIND_RETURN], true)) {
                throw new BusinessLogicException(trans_message('material_consumption.fact_kind_invalid'), 422);
            }

            $unitId = (int) $data['unit_id'];
            if (! MeasurementUnit::query()->whereKey($unitId)->where('organization_id', $organizationId)->exists()) {
                throw new BusinessLogicException(trans_message('material_consumption.rate_scope_invalid'), 422);
            }

            $occurredOn = (string) $data['occurred_on'];
            $asOf = $work->completion_date?->toDateString() ?? $occurredOn;
            $rate = $this->rateService->findApprovedForWork(
                $organizationId,
                (int) $material->id,
                (int) $work->work_type_id,
                $work->estimate_item_id !== null ? (int) $work->estimate_item_id : null,
                $asOf,
            );
            if ($rate === null) {
                throw new BusinessLogicException(trans_message('material_consumption.rate_not_approved'), 422);
            }

            $converted = $this->unitConversion->convert(
                (string) $data['quantity'],
                $unitId,
                (int) $rate->material_unit_id,
                isset($data['conversion']) && is_array($data['conversion']) ? $data['conversion'] : null,
            );

            $movementId = isset($data['warehouse_movement_id']) ? (int) $data['warehouse_movement_id'] : null;
            $movement = $this->assertMovement($organizationId, (int) $work->project_id, (int) $material->id, $movementId);
            $qualityId = isset($data['quality_document_id']) ? (int) $data['quality_document_id'] : null;
            $this->assertQualityDocument($organizationId, (int) $work->project_id, $qualityId);

            $batchNumber = isset($data['batch_number']) ? trim((string) $data['batch_number']) : null;
            if ($batchNumber === '') {
                $batchNumber = null;
            }
            if ($batchNumber === null && $movement !== null) {
                $metadata = is_array($movement->metadata) ? $movement->metadata : [];
                $fromMetadata = $metadata['batch_number'] ?? null;
                $batchNumber = is_string($fromMetadata) && $fromMetadata !== '' ? $fromMetadata : null;
            }

            $payload = [
                'completed_work_id' => (int) $work->id,
                'material_id' => (int) $material->id,
                'kind' => $kind,
                'quantity' => MaterialConsumptionQuantity::format(MaterialConsumptionQuantity::of((string) $data['quantity'])),
                'unit_id' => $unitId,
                'converted_quantity' => $converted['quantity'],
                'conversion_basis' => $converted['basis'],
                'warehouse_movement_id' => $movementId,
                'batch_number' => $batchNumber,
                'quality_document_id' => $qualityId,
                'occurred_on' => $occurredOn,
                'deviation_reason' => isset($data['deviation_reason']) ? trim((string) $data['deviation_reason']) : null,
                'agreed_by_user_id' => isset($data['agreed_by_user_id']) ? (int) $data['agreed_by_user_id'] : null,
            ];
            $payloadHash = $this->payloadHash($payload);
            $operationKey = (string) $data['operation_key'];

            $existing = MaterialConsumptionFact::query()
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new BusinessLogicException(trans_message('material_consumption.fact_idempotency_conflict'), 409);
                }

                return $existing;
            }

            $net = $this->netConverted($organizationId, (int) $work->id, (int) $material->id);
            $delta = BigDecimal::of($converted['quantity']);
            if ($kind === MaterialConsumptionFact::KIND_RETURN) {
                $delta = $delta->negated();
            }
            if ($net->plus($delta)->isNegative()) {
                throw new BusinessLogicException(trans_message('material_consumption.fact_return_exceeds_consumption'), 422);
            }

            $issued = $this->issuedQuantity($organizationId, (int) $work->id, (int) $material->id, $movement);
            $newNet = $net->plus($delta);
            $remainder = $issued === null ? null : MaterialConsumptionQuantity::format($issued->minus($newNet));

            return MaterialConsumptionFact::query()->create([
                ...$payload,
                'organization_id' => $organizationId,
                'project_id' => (int) $work->project_id,
                'rate_id' => (int) $rate->id,
                'site_remainder_quantity' => $remainder,
                'agreed_at' => $payload['agreed_by_user_id'] !== null ? now() : null,
                'operation_key' => $operationKey,
                'payload_hash' => $payloadHash,
                'created_by_user_id' => $userId,
            ]);
        });
    }

    public function netConverted(int $organizationId, int $completedWorkId, int $materialId): BigDecimal
    {
        $facts = MaterialConsumptionFact::query()
            ->where('organization_id', $organizationId)
            ->where('completed_work_id', $completedWorkId)
            ->where('material_id', $materialId)
            ->lockForUpdate()
            ->get();

        $total = BigDecimal::zero();
        foreach ($facts as $fact) {
            $quantity = BigDecimal::of((string) $fact->converted_quantity);
            $total = $fact->isReturn() ? $total->minus($quantity) : $total->plus($quantity);
        }

        return MaterialConsumptionQuantity::scale($total);
    }

    private function lockedWork(int $organizationId, int $workId): CompletedWork
    {
        $work = CompletedWork::query()
            ->physicalFacts()
            ->whereKey($workId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if ($work === null || $work->work_type_id === null || ! $work->isProductionFact()) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_work_invalid'), 422);
        }

        return $work;
    }

    private function assertMovement(
        int $organizationId,
        int $projectId,
        int $materialId,
        ?int $movementId,
    ): ?WarehouseMovement {
        if ($movementId === null) {
            return null;
        }

        $movement = WarehouseMovement::query()
            ->whereKey($movementId)
            ->where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('project_id', $projectId)
            ->first();
        if ($movement === null) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_movement_invalid'), 422);
        }

        return $movement;
    }

    private function assertQualityDocument(int $organizationId, int $projectId, ?int $documentId): void
    {
        if ($documentId === null) {
            return;
        }

        $document = ExecutiveDocument::query()
            ->whereKey($documentId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->first();
        $allowed = [
            ExecutiveDocumentTypeEnum::QUALITY_PASSPORT->value,
            ExecutiveDocumentTypeEnum::MATERIAL_CERTIFICATE->value,
            ExecutiveDocumentTypeEnum::INCOMING_BATCH_CONTROL->value,
        ];
        $type = $document?->document_type instanceof ExecutiveDocumentTypeEnum
            ? $document->document_type->value
            : (string) $document?->document_type;
        if ($document === null || ! in_array($type, $allowed, true)) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_quality_invalid'), 422);
        }
    }

    private function issuedQuantity(
        int $organizationId,
        int $completedWorkId,
        int $materialId,
        ?WarehouseMovement $current,
    ): ?BigDecimal {
        $movementIds = MaterialConsumptionFact::query()
            ->where('organization_id', $organizationId)
            ->where('completed_work_id', $completedWorkId)
            ->where('material_id', $materialId)
            ->whereNotNull('warehouse_movement_id')
            ->pluck('warehouse_movement_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($current !== null) {
            $movementIds[] = (int) $current->id;
        }
        $movementIds = array_values(array_unique($movementIds));
        if ($movementIds === []) {
            return null;
        }

        $movements = WarehouseMovement::query()->whereKey($movementIds)->get();
        $total = BigDecimal::zero();
        foreach ($movements as $movement) {
            $quantity = BigDecimal::of((string) $movement->quantity);
            if ($movement->movement_type === WarehouseMovement::TYPE_RETURN) {
                $total = $total->minus($quantity);
            } else {
                $total = $total->plus($quantity);
            }
        }

        return $total->toScale(6, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        $encoded = json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
