<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTOs\AdvanceAccountSettingDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdvanceSetting\StoreOrUpdateAdvanceSettingRequest;
use App\Http\Responses\AdminResponse;
use App\Services\AdvanceAccountSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class AdvanceAccountSettingController extends Controller
{
    public function __construct(private readonly AdvanceAccountSettingService $settingsService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(
                trans_message('advance_settings.organization_context_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $settings = $this->settingsService->getSettings($organizationId);
            $dto = AdvanceAccountSettingDTO::fromModelOrDefaults($settings, $organizationId);

            return AdminResponse::success($dto);
        } catch (Throwable $e) {
            Log::error('advance_settings.index.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('advance_settings.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function storeOrUpdate(StoreOrUpdateAdvanceSettingRequest $request): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(
                trans_message('advance_settings.organization_context_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $validatedData = [];

        try {
            $validatedData = $request->validated();
            $updatedSettings = $this->settingsService->updateSettings($organizationId, $validatedData);
            $dto = AdvanceAccountSettingDTO::fromModel($updatedSettings);

            return AdminResponse::success(
                $dto,
                trans_message('advance_settings.updated')
            );
        } catch (Throwable $e) {
            Log::error('advance_settings.store_or_update.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'data' => $validatedData,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('advance_settings.update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
