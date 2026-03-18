<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\Models\User;

class AIPermissionChecker
{
    private const MEMBER_TOOLS = [
        'generate_profitability_report',
        'generate_work_completion_report',
        'generate_material_movements_report',
        'generate_contractor_settlements_report',
        'generate_warehouse_stock_report',
        'generate_time_tracking_report',
        'generate_contract_payments_report',
        'generate_project_timelines_report',
        'search_projects',
        'search_warehouse',
        'search_materials',
        'search_users',
        'search_contractors',
    ];

    private const PRIVILEGED_TOOLS = [
        'approve_payment_request',
        'create_schedule_task',
        'update_schedule_task_status',
        'send_project_notification',
    ];

    public function canUseAssistant(User $user, int $organizationId): bool
    {
        if ($organizationId <= 0) {
            return false;
        }

        return $user->belongsToOrganization($organizationId);
    }

    public function canAccessConversation(User $user, Conversation $conversation, int $organizationId): bool
    {
        if (!$this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        if ((int) $conversation->organization_id !== $organizationId) {
            return false;
        }

        if ((int) $conversation->user_id === (int) $user->id) {
            return true;
        }

        return $this->canAccessOrganizationConversationsInAdmin($user, $organizationId);
    }

    public function canManageOrganizationConversations(User $user, int $organizationId): bool
    {
        if (!$this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return $user->isOrganizationAdmin($organizationId) || $user->isOrganizationOwner($organizationId);
    }

    public function canAccessOrganizationConversationsInAdmin(User $user, int $organizationId): bool
    {
        if (!$this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return $user->isAdminPanelUser($organizationId);
    }

    public function canExecuteTool(User $user, string $toolName, array $params = []): bool
    {
        unset($params);

        $organizationId = (int) $user->current_organization_id;

        if (!$this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        if (in_array($toolName, self::MEMBER_TOOLS, true)) {
            return true;
        }

        if (in_array($toolName, self::PRIVILEGED_TOOLS, true)) {
            return $user->isOrganizationAdmin($organizationId) || $user->isOrganizationOwner($organizationId);
        }

        return false;
    }
}
