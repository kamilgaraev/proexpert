<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WriteOffRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseStorageCellResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileProjectAccessResolver;
use DomainException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Throwable;

final class WarehouseWriteOffController extends Controller
{
    public function __construct(
        private readonly WarehouseService $warehouse,
        private readonly WarehouseStorageCellResolver $storageCellResolver,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function __invoke(WriteOffRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;
        $data = $request->validated();

        try {
            if (! empty($data['project_id'])) {
                $this->projectAccess->assert(
                    $user,
                    $organizationId,
                    (int) $data['project_id'],
                    trans_message('mobile_schedule.errors.project_not_found'),
                );
            }

            $cell = $this->storageCellResolver->resolveForWarehouse(
                $organizationId,
                (int) $data['warehouse_id'],
                isset($data['cell_id']) ? (int) $data['cell_id'] : null,
            );

            $result = $this->warehouse->writeOffAsset(
                $organizationId,
                (int) $data['warehouse_id'],
                (int) $data['material_id'],
                (float) $data['quantity'],
                [
                    'cell_id' => $cell?->id,
                    'project_id' => $data['project_id'] ?? null,
                    'user_id' => (int) $user->id,
                    'document_number' => $data['document_number'] ?? null,
                    'reason' => $data['reason'],
                    'operation_category' => $data['operation_category'],
                    'metadata' => $data['metadata'] ?? [],
                    'idempotency_key' => $data['idempotency_key'],
                ],
            );

            return MobileResponse::success([
                'movement_id' => (int) $result['movement']->id,
                'remaining_total_quantity' => $result['remaining_total_quantity'] ?? null,
            ], trans_message('warehouse_basic.write_off_success'));
        } catch (WarehouseOperationIdempotencyConflictException $exception) {
            return MobileResponse::error($exception->getMessage(), 409);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (InvalidArgumentException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }
}
