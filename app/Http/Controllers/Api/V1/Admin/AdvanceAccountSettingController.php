<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdvanceAccountSettingService;
use App\Http\Requests\Api\V1\Admin\AdvanceSetting\StoreOrUpdateAdvanceSettingRequest;
use App\DTOs\AdvanceAccountSettingDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdvanceAccountSettingController extends Controller
{
    protected AdvanceAccountSettingService $settingsService;

    public function __construct(AdvanceAccountSettingService $settingsService)
    {
        $this->settingsService = $settingsService;
        // Можно добавить middleware для проверки прав, если это не сделано в роутах или FormRequest
        // $this->middleware('can:manage_advance_settings');
    }

    /**
     * Получить текущие настройки подотчетных средств для организации пользователя.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        if (!$organizationId) {
            return response()->json(['success' => false, 'message' => 'Organization context not found.'], 400);
        }

        try {
            $settings = $this->settingsService->getSettings($organizationId);
            $dto = AdvanceAccountSettingDTO::fromModelOrDefaults($settings, $organizationId);
            
            return response()->json([
                'success' => true,
                'data' => $dto
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching advance account settings in controller', [
                'organization_id' => $organizationId,
                'exception' => $e
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to retrieve settings.'], 500);
        }
    }

    /**
     * Сохранить или обновить настройки подотчетных средств для организации пользователя.
     *
     * @param StoreOrUpdateAdvanceSettingRequest $request
     * @return JsonResponse
     */
    public function storeOrUpdate(StoreOrUpdateAdvanceSettingRequest $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        if (!$organizationId) {
            // Эта проверка также есть в StoreOrUpdateAdvanceSettingRequest->authorize(), но дублирование не помешает
            return response()->json(['success' => false, 'message' => 'Organization context not found.'], 400);
        }

        try {
            $validatedData = $request->validated();
            $updatedSettings = $this->settingsService->updateSettings($organizationId, $validatedData);
            $dto = AdvanceAccountSettingDTO::fromModel($updatedSettings);

            return response()->json([
                'success' => true,
                'message' => 'Advance account settings updated successfully.',
                'data' => $dto
            ]);
        } catch (\Exception $e) {
            Log::error('Error storing/updating advance account settings in controller', [
                'organization_id' => $organizationId,
                'validated_data' => $validatedData ?? [],
                'exception' => $e
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to update settings: ' . $e->getMessage()], 500);
        }
    }
} 