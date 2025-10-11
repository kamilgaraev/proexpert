<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;

/**
 * Проверка разрешений для действий ИИ
 */
class AIPermissionChecker
{
    /**
     * Проверить, может ли пользователь выполнить действие
     */
    public function canExecuteAction(User $user, string $action, array $params = []): bool
    {
        // Базовая проверка - пользователь должен существовать
        if (!$user) {
            return false;
        }

        $organizationId = $user->current_organization_id;
        if (!$organizationId) {
            return false;
        }

        // Проверка роли пользователя в организации
        if (!$user->isOrganizationAdmin($organizationId) && !$user->isOrganizationOwner($organizationId)) {
            return false;
        }

        // Специфические проверки для разных действий
        return match($action) {
            'create_measurement_unit',
            'update_measurement_unit',
            'delete_measurement_unit' => $this->canManageMeasurementUnits($user, $params),

            default => false
        };
    }

    /**
     * Получить требуемую роль для действия
     */
    public function getRequiredRole(string $action): string
    {
        return match($action) {
            'create_measurement_unit',
            'update_measurement_unit',
            'delete_measurement_unit' => 'admin',

            default => 'admin'
        };
    }

    /**
     * Получить область действия (project, contract, global)
     */
    public function getActionScope(string $action): string
    {
        return match($action) {
            'create_measurement_unit',
            'update_measurement_unit',
            'delete_measurement_unit' => 'global',

            default => 'global'
        };
    }

    /**
     * Проверка разрешений на управление единицами измерения
     */
    private function canManageMeasurementUnits(User $user, array $params = []): bool
    {
        // Для MVP - любой админ может управлять единицами измерения
        // В будущем можно добавить более гранулярные разрешения
        return true;
    }
}
