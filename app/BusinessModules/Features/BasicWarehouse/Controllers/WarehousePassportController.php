<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehousePassportUploadRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePassportService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WarehousePassportController extends Controller
{
    public function __construct(private readonly WarehousePassportService $passports) {}

    public function store(WarehousePassportUploadRequest $request, int $movementId): JsonResponse
    {
        try {
            $passport = $this->passports->upload(
                (int) $request->user()->current_organization_id,
                $movementId,
                $request->file('passport'),
                $request->user()
            );

            return AdminResponse::success($passport, trans_message('warehouse_basic.passport_upload_success'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('warehouse_basic.movement_not_found'), 404);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            Log::error('WarehousePassportController::store', [
                'movement_id' => $movementId,
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('warehouse_basic.passport_upload_failed'), 500);
        }
    }
}
