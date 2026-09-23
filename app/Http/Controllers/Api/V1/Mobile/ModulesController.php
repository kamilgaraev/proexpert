<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ListMobileModulesRequest;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileModulesService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ModulesController extends Controller
{
    public function __construct(
        private readonly MobileModulesService $modulesService
    ) {
    }

    public function index(ListMobileModulesRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_modules.errors.unauthorized'), 401);
            }

            $validated = $request->validated();

            return MobileResponse::success($this->modulesService->build(
                $user,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            ));
        } catch (AuthorizationException $exception) {
            return MobileResponse::error($exception->getMessage(), 403);
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
