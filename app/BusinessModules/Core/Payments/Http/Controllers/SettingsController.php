<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    private const CACHE_KEY_PREFIX = 'payments_settings_';
    private const CACHE_TTL = 3600;
    
    /**
     * Получить настройки модуля
     * 
     * GET /api/v1/admin/payments/settings
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $settings = $this->getSettings($organizationId);
            
            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.settings.show.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить настройки',
            ], 500);
        }
    }
    
    /**
     * Обновить настройки
     * 
     * PUT /api/v1/admin/payments/settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'default_payment_terms_days' => 'nullable|integer|min:1|max:365',
            'enable_auto_overdue' => 'nullable|boolean',
            'overdue_notification_enabled' => 'nullable|boolean',
            'overdue_notification_days_before' => 'nullable|integer|min:1|max:30',
            'allow_partial_payments' => 'nullable|boolean',
            'require_payment_approval' => 'nullable|boolean',
            'default_vat_rate' => 'nullable|numeric|min:0|max:100',
            'default_currency' => 'nullable|string|in:RUB,USD,EUR',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $currentSettings = $this->getSettings($organizationId);
            $updatedSettings = array_merge($currentSettings, $request->only([
                'default_payment_terms_days',
                'enable_auto_overdue',
                'overdue_notification_enabled',
                'overdue_notification_days_before',
                'allow_partial_payments',
                'require_payment_approval',
                'default_vat_rate',
                'default_currency',
            ]));
            
            // Сохранить в кеш
            Cache::put(
                self::CACHE_KEY_PREFIX . $organizationId,
                $updatedSettings,
                self::CACHE_TTL
            );
            
            // TODO: Сохранить в БД (таблица organization_module_settings или config)
            
            return response()->json([
                'success' => true,
                'message' => 'Настройки успешно обновлены',
                'data' => $updatedSettings,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.settings.update.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить настройки',
            ], 500);
        }
    }
    
    /**
     * Получить настройки для организации
     */
    private function getSettings(int $organizationId): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . $organizationId,
            self::CACHE_TTL,
            function () {
                return $this->getDefaultSettings();
            }
        );
    }
    
    /**
     * Настройки по умолчанию
     */
    private function getDefaultSettings(): array
    {
        return [
            'default_payment_terms_days' => 30,
            'enable_auto_overdue' => true,
            'overdue_notification_enabled' => true,
            'overdue_notification_days_before' => 3,
            'allow_partial_payments' => true,
            'require_payment_approval' => false,
            'default_vat_rate' => 20,
            'default_currency' => 'RUB',
            'invoice_number_format' => 'INV-{YEAR}-{NUMBER:6}',
        ];
    }
}

