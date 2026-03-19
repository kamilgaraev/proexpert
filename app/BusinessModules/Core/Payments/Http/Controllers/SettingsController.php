<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\OrganizationModuleActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

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
            $organizationId = (int) $request->attributes->get('current_organization_id');
            
            $settings = $this->getSettings($organizationId);
            
            return AdminResponse::success($settings, trans_message('payments.settings.loaded'));
        } catch (\Exception $e) {
            Log::error('payments.settings.show.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.settings.load_error'), 500);
        }
    }
    
    /**
     * Обновить настройки
     * 
     * PUT /api/v1/admin/payments/settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'default_payment_terms_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'enable_auto_overdue' => ['nullable', 'boolean'],
                'overdue_notification_enabled' => ['nullable', 'boolean'],
                'overdue_notification_days_before' => ['nullable', 'integer', 'min:1', 'max:30'],
                'allow_partial_payments' => ['nullable', 'boolean'],
                'require_payment_approval' => ['nullable', 'boolean'],
                'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'default_currency' => ['nullable', 'string', 'in:RUB,USD,EUR'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            
            $currentSettings = $this->getSettings($organizationId);
            $updatedSettings = array_merge($currentSettings, $validated);
            
            Cache::put(
                self::CACHE_KEY_PREFIX . $organizationId,
                $updatedSettings,
                self::CACHE_TTL
            );

            $this->persistSettings($organizationId, $updatedSettings);

            return AdminResponse::success($updatedSettings, trans_message('payments.settings.updated'));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.settings.update.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.settings.update_error'), 500);
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
            function () use ($organizationId) {
                $saved = $this->loadPersistedSettings($organizationId);
                return array_merge($this->getDefaultSettings(), $saved);
            }
        );
    }

    /**
     * Загрузить настройки из БД (OrganizationModuleActivation.module_settings)
     */
    private function loadPersistedSettings(int $organizationId): array
    {
        $activation = OrganizationModuleActivation::whereHas('module', fn ($q) => $q->where('slug', 'payments'))
            ->where('organization_id', $organizationId)
            ->first();

        return $activation?->module_settings['payment_settings'] ?? [];
    }

    /**
     * Сохранить настройки в БД
     */
    private function persistSettings(int $organizationId, array $settings): void
    {
        $activation = OrganizationModuleActivation::whereHas('module', fn ($q) => $q->where('slug', 'payments'))
            ->where('organization_id', $organizationId)
            ->first();

        if (!$activation) {
            return;
        }

        $moduleSettings = $activation->module_settings ?? [];
        $moduleSettings['payment_settings'] = $settings;

        $activation->update(['module_settings' => $moduleSettings]);
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

