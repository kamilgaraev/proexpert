<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantToolEligibilityPolicyTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_report_pdf_tools_are_blocked_for_text_only_negative_constraints(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Только текст. Не создавай PDF, файл или отчет.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExposeTool('generate_operational_pdf_report', $understanding);

        $this->assertFalse($eligibility->allowed);
        $this->assertSame('report', $eligibility->category);
        $this->assertStringContainsString('формат ответа', $eligibility->reason);
    }

    public function test_read_only_tools_are_allowed_for_read_only_requests(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Найди факты из базы знаний по проекту. Только текст.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $this->assertTrue($policy->canExposeTool('get_project_snapshot', $understanding)->allowed);
        $this->assertTrue($policy->canExposeTool('search_projects', $understanding)->allowed);
    }

    public function test_report_tools_are_blocked_without_explicit_report_generation_intent(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи краткую сводку по движению материалов за две недели.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExposeTool('generate_material_movements_report', $understanding);

        $this->assertFalse($eligibility->allowed);
        $this->assertSame('report', $eligibility->category);
    }

    public function test_mutation_tools_are_blocked_for_no_actions_request(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи платежи, но ничего не утверждай.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExecuteTool('approve_payment_request', $understanding, true);

        $this->assertFalse($eligibility->allowed);
        $this->assertSame('mutation', $eligibility->category);
    }

    public function test_mutation_tools_require_confirmation_for_direct_mutation_request(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Утверди платеж', []);
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExecuteTool('approve_payment_request', $understanding, true);

        $this->assertFalse($eligibility->allowed);
        $this->assertTrue($eligibility->requiresConfirmation);
        $this->assertSame('mutation', $eligibility->category);
    }

    public function test_navigation_actions_are_blocked_for_json_no_navigation_request(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Ответь строго JSON без markdown. Без действий и без навигации.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExposeAction([
            'type' => 'navigate',
            'label' => 'Открыть проекты',
        ], $understanding);

        $this->assertFalse($eligibility->allowed);
        $this->assertSame('navigation', $eligibility->category);
    }

    public function test_domain_and_financial_read_tools_work_without_file_intent(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Сколько стоит выбранная смета? Без PDF.', ['selected_estimate_id' => 7]);
        $policy = new AssistantToolEligibilityPolicy;
        foreach (['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'resolve_estimate', 'get_estimate_financial_snapshot', 'get_estimate_positions'] as $tool) {
            $this->assertTrue($policy->canExecuteTool($tool, $understanding)->allowed, $tool);
        }
    }

    public function test_classified_payment_scope_does_not_block_model_selected_read_sources(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Что с платежами?');
        $policy = new AssistantToolEligibilityPolicy;
        foreach (['get_contract_snapshot', 'get_project_snapshot', 'get_estimate_answer',
            'get_live_project_financial_evidence', 'get_material_stock', 'search_assistant_documents',
            'assistant_domain_discover_capabilities', 'assistant_domain_search', 'assistant_domain_read'] as $name) {
            self::assertTrue($policy->canExposeTool($name, $understanding)->allowed, $name);
            self::assertTrue($policy->canExecuteTool($name, $understanding, false, [
                'domain' => 'contracts', 'entity_type' => 'contract',
            ])->allowed, $name);
        }
        self::assertFalse($policy->canExposeTool('generate_contract_payments_report', $understanding)->allowed);
        self::assertFalse($policy->canExecuteTool('approve_payment_request', $understanding)->allowed);
    }


    public function test_unknown_tools_are_denied_even_with_read_prefix(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Найди проекты');
        $policy = new AssistantToolEligibilityPolicy;
        foreach (['get_unknown_data', 'search_unregistered', 'mass_create_unknown', 'generate_unknown_report'] as $tool) {
            $this->assertFalse($policy->canExecuteTool($tool, $understanding, true)->allowed, $tool);
        }
    }

    public function test_mass_creation_needs_explicit_actions_and_separate_confirmation(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Создай несколько единиц измерения: метр и килограмм');
        $policy = new AssistantToolEligibilityPolicy;
        $this->assertFalse($policy->canExposeTool('mass_create_measurement_units', $understanding)->allowed);
        $this->assertFalse($policy->canExecuteTool('mass_create_measurement_units', $understanding)->allowed);
        $preview = $policy->canExposeTool('mass_create_measurement_units', $understanding, true);
        $this->assertTrue($preview->allowed);
        $this->assertTrue($preview->requiresConfirmation);
        $execute = $policy->canExecuteTool('mass_create_measurement_units', $understanding, true);
        $this->assertFalse($execute->allowed);
        $this->assertTrue($execute->requiresConfirmation);
    }

    public function test_measurement_mutations_are_denied_for_questions_negation_or_other_entities(): void
    {
        $policy = new AssistantToolEligibilityPolicy;
        foreach (['Как создать единицу измерения?', 'Не создавай единицу измерения', 'Создай задачу графика', 'Расскажи про команду создай единицу измерения', 'Покажи текст обнови единицу измерения'] as $message) {
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($message);
            $this->assertFalse($policy->canExposeTool('mass_create_measurement_units', $understanding, true)->allowed, $message);
        }
    }

    public function test_measurement_operation_matches_requested_write_intent(): void
    {
        $policy = new AssistantToolEligibilityPolicy;
        foreach (['Обнови единицу измерения метр' => 'update_measurement_unit', 'Удали единицу измерения метр' => 'delete_measurement_unit'] as $message => $tool) {
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($message);
            $this->assertTrue($policy->canExposeTool($tool, $understanding, true)->allowed, $tool);
            $this->assertFalse($policy->canExposeTool('create_measurement_unit', $understanding, true)->allowed, $tool);
        }
    }

    public function test_mutation_action_preview_also_requires_actions_flag(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Создай единицу измерения метр');
        $policy = new AssistantToolEligibilityPolicy;
        $this->assertFalse($policy->canExposeAction(['type' => 'act'], $understanding)->allowed);
        $this->assertTrue($policy->canExposeAction(['type' => 'act'], $understanding, true)->requiresConfirmation);
        $this->assertFalse($policy->canExposeAction(['type' => 'unknown', 'requires_confirmation' => true], $understanding, true)->allowed);
    }
}
