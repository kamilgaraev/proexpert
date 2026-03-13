<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileModulesService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ModulesController extends Controller
{
    public function __construct(
        private readonly MobileModulesService $modulesService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_modules.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->modulesService->build($user));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.modules.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_modules.errors.load_failed'), 500);
        }
    }
}
