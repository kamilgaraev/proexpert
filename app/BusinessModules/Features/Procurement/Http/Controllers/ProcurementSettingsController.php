<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Procurement\ProcurementModule;
use App\BusinessModules\Features\Procurement\Http\Requests\UpdateProcurementSettingsRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProcurementSettingsController extends Controller
{
    public function __construct(
        private readonly ProcurementModule $module
    ) {}

    /**
     * Получить настройки модуля
     * 
     * GET /api/v1/admin/procurement/settings
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $settings = $this->module->getSettings($organizationId);
            
            return AdminResponse::success($settings, trans_message('procurement.settings_loaded'));
        } catch (\Exception $e) {
            Log::error('procurement.settings.show.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(trans_message('procurement.settings_load_error'), 500);
        }
    }

    /**
     * Обновить настройки модуля
     * 
     * PUT /api/v1/admin/procurement/settings
     */
    public function update(UpdateProcurementSettingsRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $this->module->applySettings($organizationId, $request->validated());
            
            return AdminResponse::success(
                $this->module->getSettings($organizationId),
                trans_message('procurement.settings_updated')
            );
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.settings.update.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(trans_message('procurement.settings_update_error'), 500);
        }
    }

    /**
     * Сбросить настройки к значениям по умолчанию
     * 
     * POST /api/v1/admin/procurement/settings/reset
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $defaultSettings = $this->module->getDefaultSettings();
            $this->module->applySettings($organizationId, $defaultSettings);
            
            return AdminResponse::success(
                $defaultSettings,
                trans_message('procurement.settings_reset')
            );
        } catch (\Exception $e) {
            Log::error('procurement.settings.reset.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(trans_message('procurement.settings_reset_error'), 500);
        }
    }
}
