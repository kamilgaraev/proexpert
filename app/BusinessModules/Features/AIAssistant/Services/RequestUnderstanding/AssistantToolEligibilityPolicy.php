<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantToolEligibility;

final class AssistantToolEligibilityPolicy
{
    private const READ_TOOLS = [
        'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'assistant_domain_discover_capabilities',
        'get_published_report_financial_evidence', 'get_live_project_financial_evidence',
        'search_assistant_documents', 'get_estimate_answer', 'get_material_stock', 'get_bim_model_elements',
        'resolve_estimate', 'get_estimate_financial_snapshot', 'get_estimate_positions', 'search_estimate_positions',
        'get_project_snapshot', 'get_procurement_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
        'search_projects', 'search_warehouse', 'search_materials', 'search_users', 'search_contractors',
    ];

    private const REPORT_TOOLS = [
        'generate_profitability_report', 'generate_work_completion_report', 'generate_material_movements_report',
        'generate_contractor_settlements_report', 'generate_warehouse_stock_report', 'generate_time_tracking_report',
        'generate_contract_payments_report', 'generate_project_timelines_report',
        'generate_operational_pdf_report', 'generate_rag_pdf_report',
    ];

    private const PAYMENT_ONLY_TOOLS = [
        'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
        'approve_payment_request', 'generate_contract_payments_report',
    ];

    private const MUTATION_TOOLS = [
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

        if (! in_array($toolName, ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation'], true)) {
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
