<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\UpdateBudgetEstimatesSettingsRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BudgetEstimatesSettingsController extends Controller
{
    public function __construct(
        private readonly BudgetEstimatesModule $module
    ) {}

    /**
     * Получить настройки модуля
     * 
     * @group Budget Estimates Settings
     * @authenticated
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $settings = $this->module->getSettings($organizationId);
            
            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.show.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить настройки модуля',
            ], 500);
        }
    }

    /**
     * Обновить настройки модуля
     * 
     * @group Budget Estimates Settings
     * @authenticated
     */
    public function update(UpdateBudgetEstimatesSettingsRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $this->module->applySettings($organizationId, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Настройки успешно обновлены',
                'data' => $this->module->getSettings($organizationId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.update.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить настройки модуля',
            ], 500);
        }
    }

    /**
     * Сбросить настройки к значениям по умолчанию
     * 
     * @group Budget Estimates Settings
     * @authenticated
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $defaultSettings = $this->module->getDefaultSettings();
            $this->module->applySettings($organizationId, $defaultSettings);
            
            return response()->json([
                'success' => true,
                'message' => 'Настройки сброшены к значениям по умолчанию',
                'data' => $defaultSettings,
            ]);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.reset.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось сбросить настройки',
            ], 500);
        }
    }

    /**
     * Получить информацию о модуле
     * 
     * @group Budget Estimates Settings
     * @authenticated
     */
    public function info(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'name' => $this->module->getName(),
                    'slug' => $this->module->getSlug(),
                    'version' => $this->module->getVersion(),
                    'description' => $this->module->getDescription(),
                    'type' => $this->module->getType()->value,
                    'billing_model' => $this->module->getBillingModel()->value,
                    'features' => $this->module->getFeatures(),
                    'permissions' => $this->module->getPermissions(),
                    'limits' => $this->module->getLimits(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.info.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить информацию о модуле',
            ], 500);
        }
    }
}

