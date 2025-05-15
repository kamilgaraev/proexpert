<?php

namespace App\Services;

use App\Models\AdvanceAccountSetting;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class AdvanceAccountSettingService
{
    /**
     * Получить настройки подотчетных средств для указанной организации.
     *
     * @param int $organizationId
     * @return AdvanceAccountSetting|null
     */
    public function getSettings(int $organizationId): ?AdvanceAccountSetting
    {
        try {
            return AdvanceAccountSetting::where('organization_id', $organizationId)->first();
        } catch (Exception $e) {
            Log::error('Error fetching advance account settings', [
                'organization_id' => $organizationId,
                'exception' => $e
            ]);
            return null;
        }
    }

    /**
     * Обновить или создать настройки подотчетных средств для указанной организации.
     *
     * @param int $organizationId
     * @param array $data
     * @return AdvanceAccountSetting
     * @throws Exception
     */
    public function updateSettings(int $organizationId, array $data): AdvanceAccountSetting
    {
        // Убедимся, что организация существует
        Organization::findOrFail($organizationId);

        try {
            // Удаляем organization_id из $data, если он там есть, чтобы не было конфликта с updateOrCreate
            if (isset($data['organization_id'])) {
                unset($data['organization_id']);
            }
            
            $settings = AdvanceAccountSetting::updateOrCreate(
                ['organization_id' => $organizationId],
                $data
            );
            return $settings;
        } catch (Exception $e) {
            Log::error('Error updating advance account settings', [
                'organization_id' => $organizationId,
                'data' => $data,
                'exception' => $e
            ]);
            throw new Exception('Failed to update advance account settings: ' . $e->getMessage());
        }
    }
    
    /**
     * Получить конкретную настройку для организации.
     * 
     * @param int $organizationId
     * @param string $key Ключ настройки (название поля в модели AdvanceAccountSetting)
     * @param mixed $default Значение по умолчанию, если настройка не найдена
     * @return mixed
     */
    public function getSettingValue(int $organizationId, string $key, mixed $default = null): mixed
    {
        $settings = $this->getSettings($organizationId);
        if ($settings && isset($settings->{$key})) {
            return $settings->{$key};
        }
        return $default;
    }

    /**
     * Получить настройки для текущей аутентифицированной организации пользователя.
     *
     * @return AdvanceAccountSetting|null
     */
    public function getCurrentOrganizationSettings(): ?AdvanceAccountSetting
    {
        $organizationId = Auth::user()->current_organization_id;
        if (!$organizationId) {
            Log::warning('Attempted to get advance account settings without current_organization_id for user: ' . Auth::id());
            return null;
        }
        return $this->getSettings($organizationId);
    }

    /**
     * Получить значение конкретной настройки для текущей организации.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getCurrentOrgSettingValue(string $key, mixed $default = null): mixed
    {
        $organizationId = Auth::user()->current_organization_id;
        if (!$organizationId) {
             Log::warning('Attempted to get advance account setting value without current_organization_id for user: ' . Auth::id(), ['key' => $key]);
            return $default;
        }
        return $this->getSettingValue($organizationId, $key, $default);
    }
} 