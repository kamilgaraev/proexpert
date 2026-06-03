<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\IssueToResponsibleRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReturnFromResponsibleRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Resources\WarehouseCustodyBalanceResource;
use App\BusinessModules\Features\BasicWarehouse\Http\Resources\WarehouseMovementResource;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class WarehouseCustodyController extends Controller
{
    public function __construct(
        private readonly WarehouseCustodyService $custodyService
    ) {
    }

    public function balances(Request $request): JsonResponse
    {
        try {
            $balances = $this->custodyService->getBalances(
                (int) $request->user()->current_organization_id,
                $request->query('project_id') !== null ? (int) $request->query('project_id') : null,
                $request->query('responsible_user_id') !== null ? (int) $request->query('responsible_user_id') : null,
                $request->query('material_id') !== null ? (int) $request->query('material_id') : null
            );

            $payload = WarehouseCustodyBalanceResource::collection($balances)->resolve($request);

            return $this->success(
                $request,
                $payload,
                trans_message('basic_warehouse.custody.loaded')
            );
        } catch (Throwable $exception) {
            return $this->error($request, 'custody_balances', $exception, trans_message('basic_warehouse.custody.errors.load_failed'));
        }
    }

    public function issue(IssueToResponsibleRequest $request): JsonResponse
    {
        try {
            $result = $this->custodyService->issueToResponsible(
                (int) $request->user()->current_organization_id,
                $request->user(),
                $request->validated()
            );

            return $this->success(
                $request,
                $this->movementPayload($result),
                trans_message('basic_warehouse.custody.issued')
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, 'custody_issue_validation', $exception, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->error($request, 'custody_issue', $exception, trans_message('basic_warehouse.custody.errors.issue_failed'));
        }
    }

    public function returnToProject(ReturnFromResponsibleRequest $request): JsonResponse
    {
        try {
            $result = $this->custodyService->returnFromResponsible(
                (int) $request->user()->current_organization_id,
                $request->user(),
                $request->validated()
            );

            return $this->success(
                $request,
                $this->movementPayload($result),
                trans_message('basic_warehouse.custody.returned')
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, 'custody_return_validation', $exception, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->error($request, 'custody_return', $exception, trans_message('basic_warehouse.custody.errors.return_failed'));
        }
    }

    private function movementPayload(array $result): array
    {
        return [
            'movement_out' => new WarehouseMovementResource($result['movement_out']),
            'movement_in' => new WarehouseMovementResource($result['movement_in']),
            'avg_price' => $result['avg_price'],
            'project_warehouse' => [
                'id' => $result['project_warehouse']->id,
                'name' => $result['project_warehouse']->name,
                'warehouse_type' => $result['project_warehouse']->warehouse_type,
            ],
            'custody_warehouse' => [
                'id' => $result['custody_warehouse']->id,
                'name' => $result['custody_warehouse']->name,
                'warehouse_type' => $result['custody_warehouse']->warehouse_type,
                'project_id' => $result['custody_warehouse']->project_id,
                'responsible_user_id' => $result['custody_warehouse']->responsible_user_id,
            ],
        ];
    }

    private function success(Request $request, mixed $data, string $message, int $status = 200): JsonResponse
    {
        if ($this->isMobile($request)) {
            return MobileResponse::success($data, $message, $status);
        }

        return AdminResponse::success($data, $message, $status);
    }

    private function error(
        Request $request,
        string $operation,
        Throwable $exception,
        string $message,
        int $status = 500
    ): JsonResponse {
        Log::error('Warehouse custody operation failed', [
            'operation' => $operation,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'payload' => $request->except(['password', 'token']),
            'exception' => $exception,
        ]);

        if ($this->isMobile($request)) {
            return MobileResponse::error($message, $status);
        }

        return AdminResponse::error($message, $status);
    }

    private function isMobile(Request $request): bool
    {
        return $request->is('api/v1/mobile/*');
    }
}
