<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileScheduleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly MobileScheduleService $scheduleService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $projectId = $request->integer('project_id');
            if ($projectId <= 0) {
                $projectId = null;
            }

            return MobileResponse::success($this->scheduleService->build($user, $projectId));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $request->input('project_id'),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.load_failed'), 500);
        }
    }
}
