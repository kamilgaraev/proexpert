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

    private const ASSISTANT_SCOPE_TOOLS = ['assistant_domain_discover_capabilities', 'search_assistant_documents', 'get_published_report_financial_evidence', 'get_live_project_financial_evidence'];

    private const DOMAIN_SCOPE_TOOLS = ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation'];

    private const ESTIMATE_TOOLS = ['resolve_estimate', 'get_estimate_answer', 'get_estimate_positions', 'search_estimate_positions', 'get_estimate_financial_snapshot'];

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
        'get_project_attention' => ['projects.view'],
        'analyze_project_schedule_impact' => ['projects.view', 'schedule.view'],
        'get_project_finance_overview' => ['projects.view', 'finance.view'],
        'get_project_workforce_summary' => ['projects.view'],
        'get_project_payment_readiness' => ['projects.view'],
        'get_document_readiness' => ['ai_assistant.chat'],
        'get_project_machinery_summary' => ['projects.view', 'machinery-operations.view'],
        'get_project_contract_roster' => ['projects.view', 'contracts.view'],
        'get_project_handover_summary' => ['projects.view', 'handover-acceptance.view'],
        'get_project_journal_readiness' => ['projects.view', 'construction-journal.view'],
        'get_project_safety_summary' => ['projects.view', 'safety-management.view'],
        'get_project_change_readiness' => ['projects.view', 'change-management.view'],
        'get_project_design_readiness' => ['projects.view', 'design-management.view'],
        'get_project_proposal_readiness' => ['projects.view', 'commercial_proposals.view'],
        'get_project_executive_readiness' => ['projects.view', 'executive-documentation.view'],
        'get_project_production_readiness' => ['projects.view', 'production-labor.view'],
        'get_project_crm_readiness' => ['projects.view', 'crm.deals.view'],
        'get_project_export_history' => ['ai_assistant.chat'],
        'get_project_report_delivery_summary' => ['ai_assistant.chat'],
        'get_project_report_catalog' => ['projects.view'],
        'get_project_camera_roster' => ['projects.view', 'video-monitoring.view'],
        'get_organization_access_review_summary' => ['access_recertification.reports.view'],
        'get_organization_one_c_exchange_summary' => ['one_c_exchange.history.view'],
        'get_contract_supplementary_readiness' => ['contracts.view'],
        'get_organization_notifications' => ['ai_assistant.chat'],
        'get_organization_activity_summary' => ['ai_assistant.chat'],
        'get_organization_supplier_roster' => ['suppliers.view'],
        'get_organization_project_roster' => ['admin.projects.view'],
        'get_project_site_request_queue' => ['projects.view', 'site_requests.view'],
        'get_project_material_request_queue' => ['projects.view', 'site_requests.view'],
        'get_project_tender_decisions' => ['tenders.view'],
        'get_project_daily_staff' => ['projects.view', 'workforce.view'],
        'get_own_project_access' => ['admin.users.view'],
        'get_organization_user_counts' => ['admin.users.view'],
        'get_assignable_user_roles' => ['admin.users.view'],
        'get_organization_business_profile' => ['ai_assistant.chat'],
        'get_own_report_workspace' => ['reports.view'],
        'get_own_organization_roles' => ['ai_assistant.chat'],
        'get_organization_verification' => ['ai_assistant.chat'],
        'get_own_report_subscriptions' => ['reports.view', 'reports.manage'],
        'get_report_subscription_catalog' => ['ai_assistant.chat'],
        'get_own_admin_permissions' => ['ai_assistant.chat'],
        'get_organization_contacts' => ['organization.manage'],
        'get_own_notification_preferences' => ['ai_assistant.chat'],
        'get_organization_credit_balance' => ['ai_assistant.chat'],
        'get_organization_active_packages' => ['billing.view'],
        'get_organization_advance_settings' => ['advance_settings.view', 'finance.view'],
        'get_organization_effective_modules' => ['ai_assistant.chat'],
        'get_organization_purchase_orders' => ['procurement.purchase_orders.view'],
        'get_catalog_lookup' => ['ai_assistant.chat'],
        'get_project_deliveries' => ['projects.view', 'warehouse.manage_stock'],
        'get_project_staff_roster' => ['projects.view', 'workforce.view'],
        'get_project_act_readiness' => ['projects.view', 'contracts.performance_acts.view'],
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

    public function canUseAssistant(User $user, int $organizationId, bool $fresh = true): bool
    {
        if ($organizationId <= 0) {
            return false;
        }

        $policy = app(AssistantDataAccessPolicy::class);

        return $policy->withCurrentChecks($user, $organizationId,
            fn (): bool => $policy->canReadDomain($user, $organizationId, 'assistant'), $fresh);
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

    public function canExecuteTool(User $user, string $toolName, array $params = [], bool $fresh = true): bool
    {
        $organizationId = (int) $user->current_organization_id;

        return app(AssistantDataAccessPolicy::class)->withCurrentChecks($user, $organizationId, function (AuthorizationService $authorization) use ($user, $toolName, $params, $organizationId, $fresh): bool {
            $previous = $this->batchAuthorization;
            $this->batchAuthorization = $fresh
                ? ($this->authorization ?? app(AuthorizationService::class))->forCurrentChecks(true)
                : $authorization;
            try {
                return $this->checkTool($user, $toolName, $params, $organizationId);
            } finally {
                $this->batchAuthorization = $previous === null ? null
                    : ($this->authorization ?? app(AuthorizationService::class))->forCurrentChecks(true);
            }
        }, $fresh);
    }

    private function checkTool(User $user, string $toolName, array $params, int $organizationId, bool $requireEntityAuthorization = true): bool
    {
        $toolName = $this->normalizeToolName($toolName);
        if (! app(AssistantDataAccessPolicy::class)->canReadDomain($user, $organizationId, 'assistant')) {
            return false;
        }

        $policy = app(AssistantDataAccessPolicy::class);
        if ($toolName === 'get_organization_advance_settings') {
            return in_array('advance-accounting', $policy->effectiveModuleSlugs($user, $organizationId), true)
                && $policy->canCurrentPermission($user, $organizationId, 'advance_settings.view')
                && $policy->canCurrentPermission($user, $organizationId, 'finance.view');
        }
        if ($toolName === 'get_project_material_stock' && ! $policy->canCurrentPermission($user, $organizationId, 'warehouse.manage_stock')) { return false; }
        if ($toolName === 'get_bim_model_elements') {
            return $policy->canReadDomain($user, $organizationId, 'design')
                && $policy->canCurrentPermission($user, $organizationId, 'design-management.models.view');
        }
        if (in_array($toolName, self::ASSISTANT_SCOPE_TOOLS, true)) {
            return $policy->canReadDomain($user, $organizationId, 'assistant');
        }
        if (in_array($toolName, self::DOMAIN_SCOPE_TOOLS, true)) {
            $domain = match ((string) ($params['domain'] ?? '')) {
                'works' => 'projects', 'acts' => 'contracts', default => (string) ($params['domain'] ?? '')
            };

            return $policy->canReadDomain($user, $organizationId, $domain);
        }
        if (in_array($toolName, self::ESTIMATE_TOOLS, true)) {
            if (! $policy->canReadDomain($user, $organizationId, 'estimates')) {
                return false;
            }

            if ($toolName === 'resolve_estimate' || ($toolName === 'get_estimate_answer' && ($params['estimate_id'] ?? null) === null)
                || ($toolName === 'search_estimate_positions'
                    && $policy->canCurrentPermission($user, $organizationId, 'budget-estimates.finance.view'))
                || ($toolName === 'get_estimate_positions' && ($params['estimate_id'] ?? null) === null
                    && is_string($params['estimate_selector'] ?? null) && trim($params['estimate_selector']) !== ''
                    && $policy->canCurrentPermission($user, $organizationId, 'budget-estimates.finance.view'))) {
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
        foreach (['project_id' => 'project', 'contract_id' => 'contract', 'task_id' => 'schedule_task', 'parent_task_id' => 'schedule_task', 'schedule_id' => 'schedule', 'payment_document_id' => 'payment_document', 'measurement_unit_id' => 'measurement_unit', 'measurement_id' => 'measurement_unit', 'warehouse_id' => 'warehouse', 'material_id' => 'material', 'user_id' => 'user'] as $key => $type) {
            if (isset($params[$key]) && ! $policy->canReadEntity($user, $organizationId, $type, (string) $params[$key])) {
                return false;
            }
        }
        $idType = match ($toolName) {
            'update_measurement_unit', 'delete_measurement_unit' => 'measurement_unit', default => null
        };
        if ($requireEntityAuthorization && $idType !== null && (! isset($params['id']) || ! $policy->canReadEntity($user, $organizationId, $idType, (string) $params['id']))) {
            return false;
        }
        if (isset($params['schedule_id'], $params['project_id'])) {
            $schedule = $policy->entityQuery($user, $organizationId, 'schedule');
            if ($schedule === null || ! $schedule->whereKey($params['schedule_id'])->where('project_id', $params['project_id'])->exists()) {
                return false;
            }
        }
        if ($toolName === 'create_schedule_task' && isset($params['parent_task_id'])) {
            $parent = $policy->entityQuery($user, $organizationId, 'schedule_task');
            if (! isset($params['schedule_id'], $params['project_id']) || $parent === null
                || ! $parent->whereKey($params['parent_task_id'])->where('schedule_id', $params['schedule_id'])
                    ->whereIn('task_type', ['summary', 'container'])->exists()) {
                return false;
            }
        }
        if ($this->isReportTool($toolName) && ! $policy->canReadDomain($user, $organizationId, 'reports')) {
            return false;
        }
        if (in_array($toolName, ['generate_contract_payments_report', 'generate_contractor_settlements_report'], true)
            && ! $this->canCurrent($user, 'payments.invoice.view', $organizationId) && ! $this->canCurrent($user, 'payments.invoice.view_all', $organizationId)) {
            return false;
        }
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

    public function canExposeTool(User $user, string $toolName, bool $fresh = true): bool
    {
        $toolName = $this->normalizeToolName($toolName);
        $organizationId = (int) $user->current_organization_id;
        $policy = app(AssistantDataAccessPolicy::class);

        return $policy->withCurrentChecks($user, $organizationId, function (AuthorizationService $authorization) use ($user, $toolName, $organizationId, $policy): bool {
            if (! $policy->canReadDomain($user, $organizationId, 'assistant')) {
                return false;
            }
            if (in_array($toolName, self::ASSISTANT_SCOPE_TOOLS, true) || in_array($toolName, self::DOMAIN_SCOPE_TOOLS, true)) {
                return true;
            }
            if (in_array($toolName, self::ESTIMATE_TOOLS, true)) {
                return $policy->canReadDomain($user, $organizationId, 'estimates');
            }
            $previous = $this->batchAuthorization;
            $this->batchAuthorization = $authorization;
            try {
                return $this->checkTool($user, $toolName, [], $organizationId, false);
            } finally {
                $this->batchAuthorization = $previous;
            }
        }, $fresh);
    }

    private function domainsForTool(string $toolName): array
    {
        return match ($toolName) {
            'search_projects', 'send_project_notification' => ['projects'],
            'get_bim_model_elements' => ['design'],
            'get_project_material_stock' => ['warehouse', 'projects'],
            'get_project_attention' => ['projects'],
            'analyze_project_schedule_impact' => ['schedule', 'projects'],
            'get_project_finance_overview' => ['finance', 'projects'],
            'get_project_workforce_summary' => ['projects'],
            'get_project_payment_readiness' => ['projects', 'finance'],
            'get_document_readiness' => ['assistant'],
            'get_project_machinery_summary' => ['projects', 'machinery'],
            'get_project_contract_roster' => ['projects', 'contracts'],
            'get_project_handover_summary' => ['projects', 'handover_acceptance'],
            'get_project_journal_readiness' => ['projects', 'core_journals'],
            'get_project_safety_summary' => ['projects', 'safety'],
            'get_project_change_readiness' => ['projects', 'change_management'],
            'get_project_design_readiness' => ['projects', 'design'],
            'get_project_proposal_readiness' => ['projects', 'commercial_processes'],
            'get_project_executive_readiness' => ['projects', 'documents'],
            'get_project_production_readiness' => ['projects', 'production_labor'],
            'get_project_crm_readiness' => ['projects', 'crm'],
            'get_project_report_catalog', 'get_project_export_history', 'get_project_report_delivery_summary' => ['projects'],
            'get_project_camera_roster' => ['projects', 'video_monitoring'],
            'get_organization_access_review_summary' => ['assistant'],
            'get_organization_one_c_exchange_summary' => ['core_integration'],
            'get_contract_supplementary_readiness' => ['contracts'],
            'get_organization_notifications' => ['assistant'],
            'get_organization_activity_summary' => ['assistant'],
            'get_organization_supplier_roster' => ['sales_supplier_catalog'],
            'get_organization_project_roster' => ['projects'],
            'get_project_site_request_queue' => ['projects', 'site_requests'],
            'get_project_material_request_queue' => ['projects', 'site_requests'],
            'get_project_tender_decisions' => ['tenders'],
            'get_project_daily_staff' => ['projects', 'workforce'],
            'get_own_project_access' => ['assistant'],
            'get_organization_user_counts', 'get_assignable_user_roles', 'get_own_admin_permissions', 'get_organization_business_profile', 'get_own_report_workspace', 'get_own_organization_roles', 'get_organization_verification', 'get_own_report_subscriptions', 'get_report_subscription_catalog', 'get_organization_contacts', 'get_own_notification_preferences', 'get_organization_credit_balance' => ['assistant'],
            'get_organization_advance_settings', 'get_organization_active_packages', 'get_organization_effective_modules' => ['assistant'],
            'get_organization_purchase_orders' => ['assistant'],
            'get_catalog_lookup' => ['assistant'],
            'get_project_deliveries' => ['projects', 'warehouse'],
            'get_project_staff_roster' => ['projects', 'workforce'],
            'get_project_act_readiness' => ['projects', 'contracts'],
            'get_project_snapshot' => ['projects', 'contracts', 'finance'],
            'search_warehouse', 'get_material_stock' => ['warehouse'], 'generate_warehouse_stock_report' => ['warehouse', 'finance'],
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
