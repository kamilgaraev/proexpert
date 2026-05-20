<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\Models\User;

class AIPermissionChecker
{
    private const TOOL_PERMISSION_MAP = [
        'generate_profitability_report' => ['reports.view', 'admin.reports.view'],
        'generate_work_completion_report' => ['reports.view', 'admin.reports.view'],
        'generate_material_movements_report' => ['reports.view', 'admin.reports.view'],
        'generate_contractor_settlements_report' => ['reports.view', 'admin.reports.view'],
        'generate_warehouse_stock_report' => ['reports.view', 'warehouse.view', 'admin.reports.view'],
        'generate_time_tracking_report' => ['reports.view', 'time_tracking.view', 'admin.reports.view'],
        'generate_contract_payments_report' => ['reports.view', 'admin.reports.view'],
        'generate_project_timelines_report' => ['reports.view', 'schedule-management.view', 'admin.reports.view'],
        'generate_operational_pdf_report' => ['reports.view', 'admin.reports.view'],
        'get_project_snapshot' => ['projects.view'],
        'get_procurement_snapshot' => ['procurement.view', 'procurement.purchase_requests.view'],
        'get_contract_snapshot' => ['contracts.view', 'admin.contracts.view'],
        'get_schedule_snapshot' => ['schedule-management.view'],
        'search_projects' => ['projects.view'],
        'search_warehouse' => ['warehouse.view'],
        'search_materials' => ['materials.view'],
        'search_users' => ['users.view'],
        'search_contractors' => ['admin.organizations.view', 'contractors.view'],
        'create_schedule_task' => ['schedule-management.edit'],
        'update_schedule_task_status' => ['schedule-management.edit'],
        'send_project_notification' => ['projects.edit'],
    ];

    private const MEMBER_TOOLS = [
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
        if (! $this->canUseAssistant($user, $organizationId)) {
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
        if (! $this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return $user->isOrganizationAdmin($organizationId) || $user->isOrganizationOwner($organizationId);
    }

    public function canAccessOrganizationConversationsInAdmin(User $user, int $organizationId): bool
    {
        if (! $this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return $user->isAdminPanelUser($organizationId);
    }

    public function canExecuteTool(User $user, string $toolName, array $params = []): bool
    {
        unset($params);

        $toolName = $this->normalizeToolName($toolName);

        $organizationId = (int) $user->current_organization_id;

        if (! $this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        foreach (self::TOOL_PERMISSION_MAP[$toolName] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        if ($this->isReportTool($toolName)) {
            return false;
        }

        if (in_array($toolName, self::MEMBER_TOOLS, true)) {
            return true;
        }

        if (in_array($toolName, self::PRIVILEGED_TOOLS, true) || $this->isMutationTool($toolName)) {
            return $user->isOrganizationAdmin($organizationId) || $user->isOrganizationOwner($organizationId);
        }

        return false;
    }

    public function isMutationTool(string $toolName): bool
    {
        $toolName = $this->normalizeToolName($toolName);

        if (in_array($toolName, self::PRIVILEGED_TOOLS, true)) {
            return true;
        }

        if (str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report')) {
            return true;
        }

        foreach (['create_', 'update_', 'delete_', 'approve_', 'send_'] as $prefix) {
            if (str_starts_with($toolName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isReportTool(string $toolName): bool
    {
        return str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report');
    }

    private function normalizeToolName(string $toolName): string
    {
        return match ($toolName) {
            'update_task_status' => 'update_schedule_task_status',
            default => $toolName,
        };
    }
}
