<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

class AIPermissionChecker
{
    private ?AuthorizationService $batchAuthorization = null;

    public function __construct(private readonly ?AuthorizationService $authorization = null) {}
    private const ASSISTANT_SCOPE_TOOLS = ['assistant_domain_discover_capabilities', 'get_published_report_financial_evidence', 'get_live_project_financial_evidence'];
    private const DOMAIN_SCOPE_TOOLS = ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation'];
    private const ESTIMATE_TOOLS = ['resolve_estimate', 'get_estimate_positions', 'get_estimate_financial_snapshot'];
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
        'generate_rag_pdf_report' => ['reports.view', 'admin.reports.view'],
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
        'mass_create_measurement_units',
        'create_measurement_unit',
        'update_measurement_unit',
        'delete_measurement_unit',
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

        $policy = app(AssistantDataAccessPolicy::class);
        return $policy->withCurrentChecks($user, $organizationId,
            fn (): bool => $policy->canReadDomain($user, $organizationId, 'assistant'), true);
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

        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    public function canManageOrganizationConversations(User $user, int $organizationId): bool
    {
        if (! $this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return $this->canCurrent($user, 'ai_assistant.conversations.manage', $organizationId);
    }

    public function canAccessOrganizationConversationsInAdmin(User $user, int $organizationId): bool
    {
        if (! $this->canUseAssistant($user, $organizationId)) {
            return false;
        }

        return false;
    }

    public function canExecuteTool(User $user, string $toolName, array $params = []): bool
    {
        $organizationId = (int) $user->current_organization_id;
        return app(AssistantDataAccessPolicy::class)->withCurrentChecks($user, $organizationId, function () use ($user, $toolName, $params, $organizationId): bool {
            $previous = $this->batchAuthorization;
            $this->batchAuthorization = ($this->authorization ?? app(AuthorizationService::class))->forCurrentChecks(true);
            try {
                return $this->checkTool($user, $toolName, $params, $organizationId);
            } finally {
                $this->batchAuthorization = $previous === null ? null
                    : ($this->authorization ?? app(AuthorizationService::class))->forCurrentChecks(true);
            }
        }, true);
    }

    private function checkTool(User $user, string $toolName, array $params, int $organizationId): bool
    {
        $toolName = $this->normalizeToolName($toolName);
        if (! app(AssistantDataAccessPolicy::class)->canReadDomain($user, $organizationId, 'assistant')) {
            return false;
        }

        $policy = app(AssistantDataAccessPolicy::class);
        if (in_array($toolName, self::ASSISTANT_SCOPE_TOOLS, true)) {
            return $policy->canReadDomain($user, $organizationId, 'assistant');
        }
        if (in_array($toolName, self::DOMAIN_SCOPE_TOOLS, true)) {
            $domain = match ((string) ($params['domain'] ?? '')) { 'works' => 'projects', 'acts' => 'contracts', default => (string) ($params['domain'] ?? '') };
            return $policy->canReadDomain($user, $organizationId, $domain);
        }
        if (in_array($toolName, self::ESTIMATE_TOOLS, true)) {
            if (! $policy->canReadDomain($user, $organizationId, 'estimates')) {
                return false;
            }

            if ($toolName === 'resolve_estimate') {
                return true;
            }

            $estimateId = $params['estimate_id'] ?? null;

            return filter_var($estimateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
                && $policy->canReadEntityContent($user, $organizationId, 'estimate', (int) $estimateId);
        }
        $domains = $this->domainsForTool($toolName);
        if ($domains === []) {
            return false;
        }
        foreach ($domains as $domain) {
            if (! $policy->canReadDomain($user, $organizationId, $domain)) {
                return false;
            }
        }
        foreach (['project_id' => 'project', 'contract_id' => 'contract', 'task_id' => 'schedule_task', 'schedule_id' => 'schedule', 'payment_document_id' => 'payment_document', 'measurement_unit_id' => 'measurement_unit', 'measurement_id' => 'measurement_unit', 'warehouse_id' => 'warehouse', 'material_id' => 'material', 'user_id' => 'user'] as $key => $type) {
            if (isset($params[$key]) && ! $policy->canReadEntity($user, $organizationId, $type, (string) $params[$key])) {
                return false;
            }
        }
        $idType = match ($toolName) { 'update_measurement_unit', 'delete_measurement_unit' => 'measurement_unit', default => null };
        if ($idType !== null && (! isset($params['id']) || ! $policy->canReadEntity($user, $organizationId, $idType, (string) $params['id']))) { return false; }
        if (isset($params['schedule_id'], $params['project_id'])) {
            $schedule = $policy->entityQuery($user, $organizationId, 'schedule');
            if ($schedule === null || ! $schedule->whereKey($params['schedule_id'])->where('project_id', $params['project_id'])->exists()) { return false; }
        }
        if ($this->isReportTool($toolName) && ! $policy->canReadDomain($user, $organizationId, 'reports')) {
            return false;
        }
        if (in_array($toolName, ['generate_contract_payments_report', 'generate_contractor_settlements_report'], true)
            && ! $this->canCurrent($user, 'payments.invoice.view', $organizationId) && ! $this->canCurrent($user, 'payments.invoice.view_all', $organizationId)) { return false; }
        $mutationPermission = match ($toolName) {
            'create_schedule_task', 'update_schedule_task_status' => 'schedule-management.edit',
            'send_project_notification' => 'projects.edit',
            'approve_payment_request' => 'payments.invoice.approve',
            'create_measurement_unit', 'mass_create_measurement_units' => 'measurement_units.create',
            'update_measurement_unit' => 'measurement_units.edit', 'delete_measurement_unit' => 'measurement_units.delete',
            default => null,
        };
        return $mutationPermission === null || $this->canCurrent($user, $mutationPermission, $organizationId);
    }

    public function hasExplicitToolPolicy(string $toolName): bool
    {
        $toolName = $this->normalizeToolName($toolName);

        return in_array($toolName, self::ASSISTANT_SCOPE_TOOLS, true)
            || in_array($toolName, self::DOMAIN_SCOPE_TOOLS, true)
            || in_array($toolName, self::ESTIMATE_TOOLS, true)
            || $this->domainsForTool($toolName) !== [];
    }

    private function domainsForTool(string $toolName): array
    {
        return match ($toolName) {
            'search_projects', 'send_project_notification' => ['projects'],
            'get_project_snapshot' => ['projects', 'contracts', 'finance'],
            'search_warehouse' => ['warehouse'], 'generate_warehouse_stock_report' => ['warehouse', 'finance'],
            'search_materials' => ['materials'], 'search_users' => ['people'], 'search_contractors' => ['contractors'],
            'get_contract_snapshot', 'generate_contractor_settlements_report', 'generate_contract_payments_report' => ['contracts', 'finance'],
            'get_procurement_snapshot' => ['procurement', 'finance'],
            'get_schedule_snapshot', 'create_schedule_task', 'update_schedule_task_status' => ['schedule'],
            'generate_profitability_report' => ['projects', 'contracts', 'finance', 'warehouse'], 'generate_work_completion_report' => ['projects', 'contracts', 'finance'],
            'generate_project_timelines_report' => ['projects', 'contracts', 'schedule', 'finance'], 'generate_material_movements_report' => ['warehouse', 'finance'], 'generate_time_tracking_report' => ['time_tracking', 'finance'],
            'generate_operational_pdf_report', 'generate_rag_pdf_report' => ['reports'],
            'approve_payment_request' => ['finance'],
            'create_measurement_unit', 'mass_create_measurement_units', 'update_measurement_unit', 'delete_measurement_unit' => ['measurement_units'],
            default => [],
        };
    }

    public function isMutationTool(string $toolName): bool
    {
        $toolName = $this->normalizeToolName($toolName);

        if (in_array($toolName, self::PRIVILEGED_TOOLS, true)) {
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

    private function canCurrent(User $user, string $permission, int $organizationId): bool
    {
        return ($this->batchAuthorization ?? $this->authorization ?? app(AuthorizationService::class))
            ->canCurrent($user, $permission, ['organization_id' => $organizationId]);
    }
}
