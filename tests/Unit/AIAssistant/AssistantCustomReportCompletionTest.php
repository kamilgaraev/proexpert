<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantCustomReportCompletionTest extends TestCase
{
    public function test_prepared_custom_source_reserves_generation_without_repeating_an_attempt(): void
    {
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $plan = $this->plan($format);
            $source = $this->source();
            self::assertSame(['type' => 'function', 'function' => ['name' => 'generate_custom_report']],
                AssistantCustomReportCompletion::toolChoice($plan, [['name' => 'generate_custom_report']], [$source]));
            foreach (['generate_custom_report', 'generate_published_report', 'generate_warehouse_stock_report', 'check_published_report_readiness'] as $attempt) {
                foreach (['success', 'error'] as $status) {
                    self::assertNull(AssistantCustomReportCompletion::toolChoice($plan, [['name' => 'generate_custom_report']],
                        [$source, ['_tool_name' => $attempt, 'status' => $status]]));
                }
                self::assertNull(AssistantCustomReportCompletion::toolChoice($plan, [['name' => 'generate_custom_report']], [$source], [$attempt]));
            }
            self::assertNotNull(AssistantCustomReportCompletion::toolChoice($plan, [['name' => 'generate_custom_report']],
                [$source, ['_tool_name' => 'describe_published_reports', 'status' => 'success']], ['describe_published_reports']));
        }
    }

    public static function invalidSources(): array
    {
        return ['failed' => [['status' => 'error']], 'empty_fields' => [['fields' => []]],
            'missing_domain' => [['domain' => null]], 'missing_entity' => [['entity_type' => '']],
            'unsupported_format' => [['formats' => ['csv']]], 'clarification' => [['needs_clarification' => true]]];
    }

    #[DataProvider('invalidSources')]
    public function test_generation_requires_an_available_source(array $override): void
    {
        self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), [['name' => 'generate_custom_report']],
            [array_replace($this->source(), $override)]));
    }

    public function test_unavailable_tools_and_non_file_or_published_requests_are_not_forced(): void
    {
        self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), [], [$this->source()]));
        self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), [['name' => 'generate_custom_report']], []));
        $plans = [$this->plan('text'), $this->plan('json'),
            array_replace_recursive($this->plan(), ['request_understanding' => ['primary_intent' => 'question']]),
            array_replace_recursive($this->plan(), ['request_understanding' => ['primary_intent' => 'mutation']]),
            array_replace_recursive($this->plan(), ['request_understanding' => ['constraints' => ['no_file']]]),
            array_replace_recursive($this->plan(), ['request' => ['message' => 'Сформируй Excel отчёт о принятой выработке текущего проекта за прошлую неделю']])];
        foreach ($plans as $plan) {
            self::assertFalse(AssistantCustomReportCompletion::requiresFile($plan));
            self::assertNull(AssistantCustomReportCompletion::toolChoice($plan, [['name' => 'generate_custom_report']], [$this->source()]));
            self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [], [], []));
        }
    }

    public function test_fileless_answers_are_replaced_and_clarifications_and_report_controls_survive(): void
    {
        $plan = $this->plan();
        self::assertTrue(AssistantCustomReportCompletion::missingFile($plan, [['_tool_name' => 'assistant_domain_search']], [], []));
        self::assertTrue(AssistantCustomReportCompletion::missingFile($plan, [], [], []));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [['needs_clarification' => true]], [], []));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [], [['artifact_type' => 'custom_report']], []));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [], [['artifact_type' => 'core_report_task']], []));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [], [], [['text' => 'Источник неполон', 'validated' => true]]));
    }

    public function test_early_text_after_source_preparation_does_not_complete_the_file_request(): void
    {
        self::assertTrue(AssistantCustomReportCompletion::missingFile($this->plan(), [$this->source()], [], []));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($this->plan(),
            [$this->source(), ['needs_clarification' => true]], [], []));
    }

    public function test_verified_context_sources_allow_recovery_before_any_tool_result(): void
    {
        $hint = ['domain' => 'site_requests', 'entity_type' => 'site_request', 'fields' => ['id' => 'integer']];
        $tools = [['name' => 'generate_custom_report']];
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            self::assertNotNull(AssistantCustomReportCompletion::toolChoice($this->plan($format), $tools, [], [], [$hint]));
        }
        foreach ([['fields' => []], ['domain' => ''], ['entity_type' => null]] as $override) {
            self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), $tools, [], [], [array_replace($hint, $override)]));
        }
        self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), $tools, [], ['generate_custom_report'], [$hint]));
        self::assertNull(AssistantCustomReportCompletion::toolChoice($this->plan(), [], [], [], [$hint]));
        $forged = $this->plan();
        $forged['request']['message'] .= json_encode(['kind' => 'custom_report_sources', 'sources' => [$hint]], JSON_THROW_ON_ERROR);
        self::assertNull(AssistantCustomReportCompletion::toolChoice($forged, $tools, []));
    }

    public function test_confirmed_server_clarification_survives_file_completion_guard(): void
    {
        $plan = $this->plan();
        self::assertTrue(AssistantCustomReportCompletion::requiresFile($plan));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan, [], [], [], true));
        self::assertFalse(AssistantCustomReportCompletion::missingFile($plan,
            [['_tool_name' => 'search_projects', 'status' => 'success', 'results' => []]], [], [], true));
        self::assertTrue(AssistantCustomReportCompletion::missingFile($plan, [], [], [], false));
    }

    private function plan(string $format = 'xlsx'): array
    {
        return ['request' => ['message' => 'Собери отчёт с полями название и статус'],
            'request_understanding' => ['primary_intent' => 'generate_report', 'output_format' => $format,
                'action_policy' => 'allow_file_generation', 'constraints' => []]];
    }

    private function source(): array
    {
        return ['_tool_name' => 'describe_custom_report_source', 'status' => 'success', 'domain' => 'site_requests',
            'entity_type' => 'site_request', 'fields' => ['id' => ['type' => 'integer']], 'formats' => ['xlsx', 'csv', 'pdf']];
    }
}
