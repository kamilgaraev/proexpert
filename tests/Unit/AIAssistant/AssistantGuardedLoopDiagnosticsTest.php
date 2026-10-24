<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantGuardedLoopDiagnostics;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class AssistantGuardedLoopDiagnosticsTest extends TestCase
{
    private const REQUEST = 'd89d43e5-fa40-4c3f-a48c-bfbc45e74fe6';

    private const TOOL = 'search_assistant_documents';

    private const CANARY = 'PRIVATE_query_content_call_source_canary';

    public function test_empty_full_and_repeated_results_remain_distinct_without_identifiers(): void
    {
        $observer = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        self::assertSame(['provider_ordinal' => 1, 'canonical_outputs_expected' => 0,
            'canonical_outputs_in_sdk_input' => 0, 'all_canonical_outputs_in_sdk_input' => null], $observer->sdkEntry([]));
        $empty = $this->observe($observer, 'call_'.self::CANARY, [], self::CANARY);
        self::assertSame('projected', $empty['observation_state']);
        self::assertSame(0, $empty['fragment_count']);
        self::assertSame(0, $empty['selected_content_bytes']);
        self::assertTrue($empty['empty']);
        self::assertNull($empty['repeat_query']);
        self::assertNull($empty['repeat_result']);

        $content = str_repeat('Ж', 504).self::CANARY;
        $full = $this->observe($observer, 'second_'.self::CANARY, [$content], self::CANARY);
        self::assertSame(1, $full['fragment_count']);
        self::assertSame(strlen($content), $full['selected_content_bytes']);
        self::assertFalse($full['empty']);
        self::assertFalse($full['content_truncated']);
        self::assertTrue($full['repeat_query']);
        self::assertFalse($full['repeat_result']);
        $repeat = $this->observe($observer, 'third_'.self::CANARY, [$content], 'DIFFERENT_PRIVATE_QUERY');
        self::assertFalse($repeat['repeat_query']);
        self::assertTrue($repeat['repeat_result']);
        self::assertSame([1, 2, 3], [$empty['tool_ordinal'], $full['tool_ordinal'], $repeat['tool_ordinal']]);
        $json = json_encode([$empty, $full, $repeat], JSON_THROW_ON_ERROR);
        foreach ([self::CANARY, $content, self::REQUEST, 'DIFFERENT_PRIVATE_QUERY', 'chunk_id', 'call_id', 'fingerprint', 'hash'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function test_sdk_entry_observes_exact_pairs_and_does_not_invent_a_next_send(): void
    {
        $observer = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        $observer->sdkEntry([]);
        $result = self::documentResult(['approved-content']);
        $output = json_encode($result, JSON_THROW_ON_ERROR);
        $observer->toolResult(self::TOOL, ['query' => 'one'], $result, 'private-call', $output, [self::TOOL]);
        $missing = $observer->sdkEntry([]);
        self::assertSame(1, $missing['canonical_outputs_expected']);
        self::assertSame(0, $missing['canonical_outputs_in_sdk_input']);
        self::assertFalse($missing['all_canonical_outputs_in_sdk_input']);
        $included = $observer->sdkEntry([['type' => 'function_call_output', 'call_id' => 'private-call', 'output' => $output]]);
        self::assertSame(1, $included['canonical_outputs_in_sdk_input']);
        self::assertTrue($included['all_canonical_outputs_in_sdk_input']);
        $altered = $observer->sdkEntry([['type' => 'function_call_output', 'call_id' => 'private-call', 'output' => $output.' ']]);
        self::assertSame(0, $altered['canonical_outputs_in_sdk_input']);
        self::assertFalse($altered['all_canonical_outputs_in_sdk_input']);
        $foreign = $observer->sdkEntry([['type' => 'function_call_output', 'call_id' => 'other-call', 'output' => $output]]);
        self::assertNull($foreign['all_canonical_outputs_in_sdk_input']);
        self::assertNull($foreign['canonical_outputs_in_sdk_input']);
        self::assertSame(['provider_ordinal', 'tool_ordinal', 'tool', 'observation_state', 'fragment_count',
            'selected_content_bytes', 'empty', 'content_truncated', 'repeat_query', 'repeat_result'],
            array_keys($this->observe($observer, 'last-call', [], 'one')));
    }

    #[DataProvider('malformedResults')]
    public function test_malformed_results_are_unknown_instead_of_empty(array|string $result): void
    {
        $observer = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        $observer->sdkEntry([]);
        $event = $observer->toolResult(self::TOOL, ['query' => self::CANARY], $result, 'call', json_encode($result, JSON_THROW_ON_ERROR), [self::TOOL]);
        self::assertSame('unknown', $event['observation_state']);
        foreach (['fragment_count', 'selected_content_bytes', 'empty', 'content_truncated', 'repeat_query', 'repeat_result'] as $field) {
            self::assertNull($event[$field]);
        }
    }

    public static function malformedResults(): array
    {
        return [['error'], [['status' => 'error']], [['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => null]]],
            [['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => [['content' => 123]]]]],
            [['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => [['content' => 'text', 'content_range' => ['truncated' => 'false']]]]]]];
    }

    public function test_sql_unknown_names_overflow_and_request_isolation_are_bounded(): void
    {
        $observer = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        $observer->sdkEntry([]);
        $sql = $observer->toolResult('get_project_attention', [], ['status' => 'success', 'data' => []], 'sql', '{}', ['get_project_attention']);
        self::assertSame('projected', $sql['observation_state']);
        self::assertNull($sql['fragment_count']);
        self::assertNull($sql['empty']);
        self::assertNull($observer->toolResult(self::CANARY, [], [], 'call', '{}', [self::CANARY]));
        self::assertNull($observer->sdkEntry([])['canonical_outputs_expected']);
        $next = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        self::assertSame(0, $next->sdkEntry([])['canonical_outputs_expected']);
        self::assertNull($this->observe($next, 'call', [], 'one')['repeat_query']);
        for ($index = 0; $index < 16; $index++) {
            $next->sdkEntry([]);
        }
        $overflow = $next->sdkEntry([]);
        self::assertNull($overflow['provider_ordinal']);
        self::assertNull($overflow['canonical_outputs_expected']);
        self::assertNull($overflow['all_canonical_outputs_in_sdk_input']);
        $tools = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        $tools->sdkEntry([]);
        for ($index = 0; $index < 16; $index++) {
            self::assertNotNull($this->observe($tools, 'call'.$index, [], 'one'));
        }
        self::assertNull($this->observe($tools, 'overflow', [], 'one'));
        self::assertNull($tools->sdkEntry([])['canonical_outputs_expected']);
    }

    public function test_production_gate_requires_guarded_active_uuid(): void
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $gate = new ReflectionMethod(AIAssistantService::class, 'guardedLoopDiagnostics');
        (new ReflectionProperty(AIAssistantService::class, 'scopedContext'))->setValue($service, null);
        self::assertNull($gate->invoke($service));
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $context = (new ReflectionClass(OrdinaryAssistantScopedContext::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(OrdinaryAssistantScopedContext::class, 'guarded'))->setValue($context, false);
        (new ReflectionProperty(AIAssistantService::class, 'scopedContext'))->setValue($service, $context);
        (new ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service, new AssistantRequest(['request_id' => self::REQUEST]));
        self::assertNull($gate->invoke($service));
        (new ReflectionProperty(OrdinaryAssistantScopedContext::class, 'guarded'))->setValue($context, true);
        (new ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service, new AssistantRequest(['request_id' => 'invalid']));
        self::assertNull($gate->invoke($service));
        (new ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service, new AssistantRequest(['request_id' => self::REQUEST]));
        $first = $gate->invoke($service);
        self::assertInstanceOf(AssistantGuardedLoopDiagnostics::class, $first);
        self::assertSame($first, $gate->invoke($service));
        (new ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service,
            new AssistantRequest(['request_id' => '295b4dfe-ad21-4da0-a543-06cb705286c8']));
        self::assertNotSame($first, $gate->invoke($service));
    }

    public function test_truncation_and_malformed_native_items_remain_distinct(): void
    {
        $observer = new AssistantGuardedLoopDiagnostics(self::REQUEST);
        $observer->sdkEntry([]);
        $result = self::documentResult(['bounded']);
        $result['data']['fragments'][0]['content_range']['truncated'] = true;
        $event = $observer->toolResult(self::TOOL, ['query' => 'one'], $result, 'call', json_encode($result, JSON_THROW_ON_ERROR), [self::TOOL]);
        self::assertTrue($event['content_truncated']);
        self::assertSame(7, $event['selected_content_bytes']);
        foreach ([[['type' => 'function_call_output', 'call_id' => [], 'output' => '{}']],
            [['type' => 'function_call_output', 'call_id' => 'call', 'output' => []]], array_fill(0, 65, [])] as $messages) {
            $observation = $observer->sdkEntry($messages);
            self::assertNull($observation['canonical_outputs_expected']);
            self::assertNull($observation['all_canonical_outputs_in_sdk_input']);
        }
    }

    private function observe(AssistantGuardedLoopDiagnostics $observer, string $call, array $contents, string $query): ?array
    {
        $result = self::documentResult($contents);

        return $observer->toolResult(self::TOOL, ['query' => $query], $result, $call, json_encode($result, JSON_THROW_ON_ERROR), [self::TOOL]);
    }

    private static function documentResult(array $contents): array
    {
        return ['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => array_map(
            static fn (string $content): array => ['content' => $content, 'content_range' => ['truncated' => false]], $contents)]];
    }
}
