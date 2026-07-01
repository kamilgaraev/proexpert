<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use PHPUnit\Framework\TestCase;

final class AssistantToolEligibilityPolicyTest extends TestCase
{
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

    public function test_mutation_tools_are_blocked_for_no_actions_request(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи платежи, но ничего не утверждай.',
            []
        );
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExecuteTool('approve_payment_request', $understanding);

        $this->assertFalse($eligibility->allowed);
        $this->assertSame('mutation', $eligibility->category);
    }

    public function test_mutation_tools_require_confirmation_for_direct_mutation_request(): void
    {
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Утверди платеж', []);
        $policy = new AssistantToolEligibilityPolicy;

        $eligibility = $policy->canExecuteTool('approve_payment_request', $understanding);

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
}
