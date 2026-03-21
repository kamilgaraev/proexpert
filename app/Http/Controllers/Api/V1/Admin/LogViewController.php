<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Log\WorkCompletionLogResource;
use App\Http\Responses\AdminResponse;
use App\Services\Admin\LogViewingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class LogViewController extends Controller
{
    public function __construct(
        protected LogViewingService $logViewingService
    ) {}

    public function getMaterialLogs(Request $request): JsonResponse
    {
        return AdminResponse::error(trans_message('logs.material_usage_deprecated'), 410);
    }

    public function getWorkLogs(Request $request): JsonResponse
    {
        try {
            $logs = $this->logViewingService->getWorkCompletionLogs($request);

            return AdminResponse::paginated(
                WorkCompletionLogResource::collection($logs->getCollection()),
                [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
                trans_message('logs.work_loaded')
            );
        } catch (BusinessLogicException $e) {
            Log::error('logs.work.business_error', [
                'user_id' => $request->user()?->id,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() >= 500 ? trans_message('logs.work_load_error') : $e->getMessage(),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('logs.work.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('logs.work_load_error'), 500);
        }
    }

    public function getSystemLogs(Request $request): JsonResponse
    {
        try {
            $logs = $this->logViewingService->getSystemLogs($request);

            return AdminResponse::paginated(
                $logs['data'],
                [
                    'current_page' => $logs['current_page'],
                    'per_page' => $logs['per_page'],
                    'total' => $logs['total'],
                    'last_page' => $logs['last_page'],
                ],
                trans_message('logs.system_loaded')
            );
        } catch (BusinessLogicException $e) {
            Log::error('logs.system.business_error', [
                'user_id' => $request->user()?->id,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                $e->getCode() >= 500 ? trans_message('logs.system_load_error') : $e->getMessage(),
                $e->getCode() >= 400 ? $e->getCode() : 400
            );
        } catch (\Throwable $e) {
            Log::error('logs.system.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('logs.system_load_error'), 500);
        }
    }
}
