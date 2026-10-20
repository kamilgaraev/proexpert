<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantToolEligibility;

final class AssistantToolEligibilityPolicy
{
    private const READ_TOOLS = [
        'describe_published_reports',
        'check_published_report_readiness',
        'describe_custom_report_source',
        'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'assistant_domain_discover_capabilities',
        'get_published_report_financial_evidence', 'get_live_project_financial_evidence',
        'search_assistant_documents', 'get_estimate_answer', 'get_material_stock', 'get_bim_model_elements',
        'get_project_material_stock',
        'get_project_attention',
        'get_project_production_schedule_summary',
        'analyze_project_schedule_impact', 'get_project_finance_overview',
        'get_project_workforce_summary',
        'get_project_payment_readiness',
        'get_document_readiness',
        'get_project_machinery_summary',
        'get_project_handover_summary', 'get_project_journal_readiness', 'get_project_safety_summary', 'get_project_change_readiness', 'get_project_design_readiness',
        'get_project_proposal_readiness', 'get_project_executive_readiness', 'get_project_production_readiness', 'get_project_crm_readiness', 'get_project_report_catalog', 'get_project_export_history', 'get_project_report_delivery_summary', 'get_project_camera_roster', 'get_organization_access_review_summary', 'get_organization_one_c_exchange_summary', 'get_contract_supplementary_readiness', 'get_organization_notifications', 'get_organization_activity_summary', 'get_organization_supplier_roster', 'get_organization_project_roster', 'get_project_site_request_queue', 'get_project_material_request_queue', 'get_project_tender_decisions', 'get_project_daily_staff', 'get_organization_user_counts', 'get_assignable_user_roles', 'get_own_admin_permissions', 'get_organization_business_profile', 'get_own_report_workspace', 'get_own_organization_roles', 'get_organization_verification', 'get_own_report_subscriptions', 'get_organization_contacts', 'get_own_notification_preferences', 'get_organization_credit_balance', 'get_rag_index_status', 'get_organization_calculation_health', 'get_organization_advance_settings', 'get_organization_active_packages', 'get_organization_effective_modules', 'get_own_project_access', 'get_organization_purchase_orders', 'get_catalog_lookup', 'get_project_deliveries', 'get_project_staff_roster', 'get_project_act_readiness',
        'get_project_contract_roster',
        'get_report_subscription_catalog',
        'resolve_estimate', 'get_estimate_financial_snapshot', 'get_estimate_positions', 'search_estimate_positions',
        'get_project_snapshot', 'get_procurement_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
        'search_projects', 'search_warehouse', 'search_materials', 'search_users', 'search_contractors',
    ];

    private const REPORT_TOOLS = [
        'generate_published_report',
        'generate_profitability_report', 'generate_work_completion_report', 'generate_material_movements_report',
        'generate_contractor_settlements_report', 'generate_warehouse_stock_report', 'generate_time_tracking_report',
        'generate_contract_payments_report', 'generate_project_timelines_report',
        'generate_operational_pdf_report', 'generate_rag_pdf_report',
        'generate_custom_report',
    ];

    private const PAYMENT_ONLY_TOOLS = [
        'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
        'approve_payment_request', 'generate_contract_payments_report',
        'generate_custom_report', 'describe_custom_report_source', 'assistant_domain_discover_capabilities',
    ];

    private const MUTATION_TOOLS = [
        'create_report_subscription', 'update_report_subscription',
        'approve_payment_request', 'create_schedule_task', 'update_schedule_task_status', 'send_project_notification',
        'create_measurement_unit', 'update_measurement_unit', 'delete_measurement_unit', 'mass_create_measurement_units',
    ];

    public function isReadOnlyTool(string $toolName): bool
    {
        return in_array($toolName, self::READ_TOOLS, true);
    }

    public function canExposeTool(string $toolName, AssistantRequestUnderstanding $understanding, bool $allowActions = false): AssistantToolEligibility
    {
        return $this->toolEligibility($toolName, $understanding, false, $allowActions);
    }

    public function canExecuteTool(
        string $toolName,
        AssistantRequestUnderstanding $understanding,
        bool $allowActions = false,
        array $arguments = []
    ): AssistantToolEligibility {
        return $this->toolEligibility($toolName, $understanding, true, $allowActions, $arguments);
    }

    public function canExposeAction(array $action, AssistantRequestUnderstanding $understanding, bool $allowActions = false): AssistantToolEligibility
    {
        $actionType = (string) ($action['type'] ?? '');

        if ($actionType === 'navigate') {
            return $understanding->blocksNavigation()
                ? AssistantToolEligibility::block('navigation', trans_message('ai_assistant.eligibility_navigation_disabled'))
                : AssistantToolEligibility::allow('navigation');
        }

        if ($actionType === 'act') {
            return $this->allowsMutation($understanding, $allowActions)
                ? AssistantToolEligibility::allow('mutation', true)
                : AssistantToolEligibility::block('mutation', trans_message('ai_assistant.eligibility_mutation_disabled'));
        }

        return AssistantToolEligibility::block('unknown', trans_message('ai_assistant.eligibility_unknown_tool'));
    }

    private function toolEligibility(
        string $toolName,
        AssistantRequestUnderstanding $understanding,
        bool $execute,
        bool $allowActions,
        array $arguments = []
    ): AssistantToolEligibility {
        $category = $this->toolCategory($toolName);

        if ($category === 'unknown') {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_unknown_tool'));
        }

        if ($category === 'read') {
            return AssistantToolEligibility::allow($category);
        }

        if ($category === 'report' && $understanding->blocksFileGeneration()) {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_report_format_disabled'));
        }

        if ($category === 'report' && $understanding->primaryIntent !== 'generate_report') {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_report_explicit_only'));
        }
        if ($category === 'report' && in_array($understanding->outputFormat, ['xlsx', 'csv'], true) && ! in_array($toolName, ['generate_custom_report', 'generate_published_report'], true)) {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_report_format_disabled'));
        }
        if ($category === 'report' && $execute && in_array($toolName, ['generate_custom_report', 'generate_published_report'], true)
            && in_array($understanding->outputFormat, ['xlsx', 'csv', 'pdf'], true)
            && ($arguments['format'] ?? null) !== $understanding->outputFormat) {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_report_format_disabled'));
        }

        if ($this->isPaymentOnlyRequest($understanding) && $this->blocksPaymentOnlyTool($toolName, $execute, $arguments)) {
            return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_payment_scope_disabled'));
        }

        if ($category === 'mutation') {
            if (! $this->allowsMutation($understanding, $allowActions) || ! $this->matchesMutationIntent($toolName, $understanding)) {
                return AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_mutation_disabled'));
            }

            return $execute
                ? AssistantToolEligibility::block($category, trans_message('ai_assistant.eligibility_confirmation_required'), true)
                : AssistantToolEligibility::allow($category, true);
        }

        return AssistantToolEligibility::allow($category);
    }

    public function isPaymentOnlyRequest(?AssistantRequestUnderstanding $understanding): bool
    {
        return $understanding instanceof AssistantRequestUnderstanding
            && count($understanding->requestedEntities) === 1
            && $understanding->requestedEntities[0] === 'payment';
    }

    public function isAllowedForPaymentOnlyRequest(string $toolName): bool
    {
        return in_array($toolName, self::PAYMENT_ONLY_TOOLS, true);
    }

    private function blocksPaymentOnlyTool(string $toolName, bool $execute, array $arguments): bool
    {
        if (! $this->isAllowedForPaymentOnlyRequest($toolName)) {
            return true;
        }

        if ($toolName === 'generate_custom_report' && $execute) {
            $sections = $arguments['sections'] ?? [];

            return $sections === [] || array_filter($sections, static fn (array $section): bool => ($section['domain'] ?? null) !== 'finance'
                || ($section['entity_type'] ?? null) !== 'payment_document') !== [];
        }
        if (! in_array($toolName, ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'describe_custom_report_source'], true)) {
            return false;
        }

        if (! $execute) {
            return false;
        }

        return ($arguments['domain'] ?? null) !== 'finance'
            || ($arguments['entity_type'] ?? null) !== 'payment_document';
    }

    private function allowsMutation(AssistantRequestUnderstanding $understanding, bool $allowActions): bool
    {
        return $allowActions
            && ! $understanding->blocksActions()
            && $understanding->actionPolicy === 'requires_confirmation'
            && in_array($understanding->primaryIntent, ['create', 'update', 'delete', 'approve', 'send'], true);
    }

    private function matchesMutationIntent(string $toolName, AssistantRequestUnderstanding $understanding): bool
    {
        $intent = match ($toolName) {
            'create_report_subscription' => 'create',
            'update_report_subscription' => 'update',
            'create_measurement_unit', 'mass_create_measurement_units', 'create_schedule_task' => 'create',
            'update_measurement_unit', 'update_schedule_task_status' => 'update',
            'delete_measurement_unit' => 'delete',
            'approve_payment_request' => 'approve',
            'send_project_notification' => 'send',
            default => null,
        };

        if ($understanding->primaryIntent !== $intent) {
            return false;
        }

        if (in_array($toolName, ['create_report_subscription', 'update_report_subscription'], true)) {
            return in_array('report_subscription', $understanding->requestedEntities, true);
        }

        return ! str_contains($toolName, 'measurement_unit')
            || in_array('measurement_unit', $understanding->requestedEntities, true);
    }

    private function toolCategory(string $toolName): string
    {
        if (in_array($toolName, self::READ_TOOLS, true)) {
            return 'read';
        }
        if (in_array($toolName, self::REPORT_TOOLS, true)) {
            return 'report';
        }
        if (in_array($toolName, self::MUTATION_TOOLS, true)) {
            return 'mutation';
        }

        return 'unknown';
    }
}
