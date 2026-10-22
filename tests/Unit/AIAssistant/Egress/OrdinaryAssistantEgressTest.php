<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils as StreamUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\AssistantEgressFixtureAuthority;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class OrdinaryAssistantEgressTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const CANARY = 'PRIVATE_CANARY_ordinary_egress';

    public static function unadmittedInputs(): array
    {
        $canary = self::CANARY;
        $tool = ['type' => 'function', 'name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => []]];

        return [
            'question' => [[['role' => 'user', 'content' => $canary]], []],
            'history' => [[['role' => 'user', 'content' => $canary], ['role' => 'user', 'content' => 'Ещё вопрос']], []],
            'summary-memory-document-context' => [[['role' => 'user', 'content' => json_encode([
                'personal_memory' => [['content' => $canary]],
                'conversation_summary' => $canary,
                'document_context' => ['rag_context' => ['content' => $canary]],
            ], JSON_THROW_ON_ERROR)]], []],
            'tool-result' => [[
                ['role' => 'user', 'content' => 'Синтетический вопрос'],
                ['type' => 'function_call', 'id' => 'fc_offline', 'status' => 'completed', 'call_id' => 'call_offline', 'name' => 'lookup', 'arguments' => '{}'],
                ['type' => 'function_call_output', 'call_id' => 'call_offline', 'output' => json_encode(['document_context' => ['content' => $canary]], JSON_THROW_ON_ERROR)],
            ], ['tools' => [$tool]]],
            'schema-description' => [[['role' => 'user', 'content' => 'Синтетический вопрос']], ['tools' => [array_merge($tool, ['description' => $canary])]]],
            'client-safe-flags' => [[['role' => 'user', 'content' => $canary]], ['is_safe' => true, 'synthetic' => true, 'privacy' => ['status' => 'ready']]],
        ];
    }

    #[DataProvider('unadmittedInputs')]
    public function test_ordinary_service_denies_unadmitted_context_before_transport_and_provider_accounting(array $input, array $options): void
    {
        $history = [];
        $service = $this->service($history);

        try {
            $service->dispatch($input, $options, self::actor());
            self::fail('Unadmitted ordinary input reached provider');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('required_stage_unavailable', $denied->reason);
            self::assertSame([], $history);
            self::assertSame(0, $service->usageRecords);
            self::assertStringNotContainsString(self::CANARY, $denied->getMessage().json_encode($denied, JSON_THROW_ON_ERROR));
            self::assertNull($denied->getPrevious());
            self::assertSame('assistant_egress_denied', (new ReflectionMethod(AIAssistantService::class, 'requestErrorCode'))->invoke($service, $denied));
        }
    }

    public function test_chat_image_bytes_are_denied_at_the_same_ordinary_boundary(): void
    {
        $history = [];
        $service = $this->service($history);
        $parts = [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode(self::CANARY), 'detail' => 'high']]];
        (new ReflectionProperty(AIAssistantService::class, 'currentImageParts'))->setValue($service, $parts);

        try {
            $service->dispatch([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Опиши'], ...$parts], '_trusted_chat_images' => true]], [], self::actor());
            self::fail('Unadmitted image reached provider');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('required_stage_unavailable', $denied->reason);
            self::assertSame([], $history);
            self::assertStringNotContainsString(base64_encode(self::CANARY), (string) $denied);
        }
    }

    public function test_explicit_offline_issuer_allows_only_exact_scoped_payload_and_real_provider_wire_matches_it(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        $input = [['role' => 'system', 'content' => 'Открытая синтетическая инструкция.'], ['role' => 'user', 'content' => 'Синтетический материал: 12,5 м³.']];
        $authority->approve($service->snapshot($input, [], self::actor()));

        $result = $service->dispatch($input, [], self::actor());

        self::assertSame('Синтетический ответ.', $result['response']['content']);
        self::assertCount(1, $history);
        self::assertSame(3, $authority->calls);
        self::assertSame(1, $service->usageRecords);
        self::assertSame($authority->approvedPayload, json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($authority->approvedScope['endpoint'], (string) $history[0]['request']->getUri());
        self::assertFalse($authority->approvedPayload['store']);
        self::assertFalse($authority->approvedPayload['stream']);
    }

    public function test_final_revocation_prevents_http_and_does_not_record_a_provider_cost(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $authority->denyAt = 2;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));

        try {
            $service->dispatch($input, [], self::actor());
            self::fail('Revoked approval reached provider');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('access_denied', $denied->reason);
            self::assertSame(2, $authority->calls);
            self::assertSame([], $history);
            self::assertSame(0, $service->usageRecords);
        }
    }

    public function test_ready_decision_for_another_payload_is_not_a_dispatch_permit(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $authority->bindingOverride = str_repeat('0', 64);
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));

        try {
            $service->dispatch($input, [], self::actor());
            self::fail('Unbound ready decision reached provider');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('scope_mismatch', $denied->reason);
            self::assertSame([], $history);
        }
    }

    public function test_admission_for_current_config_does_not_authorize_an_older_provider_instance_endpoint(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        config(['ai-assistant.llm.timeweb.base_uri' => 'https://approved-new.invalid/v1']);
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));

        try {
            $service->dispatch($input, [], self::actor());
            self::fail('A shadow endpoint admission reached a different actual endpoint');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('scope_mismatch', $denied->reason);
            self::assertSame(3, $authority->calls);
            self::assertSame([], $history);
            self::assertSame(0, $service->usageRecords);
        }
    }

    public function test_payload_mutation_after_sdk_serialization_is_denied_before_actual_transport(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        $client = (new ReflectionProperty(TimewebProvider::class, 'httpClient'))->getValue($service->provider());
        $client->getConfig('handler')->push(Middleware::mapRequest(static fn (RequestInterface $wire): RequestInterface => $wire->withBody(StreamUtils::streamFor(str_replace('Синтетический вопрос', self::CANARY, (string) $wire->getBody())))));
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));

        try {
            $service->dispatch($input, [], self::actor());
            self::fail('Post-SDK mutation reached actual transport');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('scope_mismatch', $denied->reason);
            self::assertSame([], $history);
            self::assertSame(0, $service->usageRecords);
        }
    }

    public function test_revocation_at_the_actual_wire_boundary_denies_without_cost_and_clears_the_attempt(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $authority->denyAt = 3;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress);
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));
        try {
            $service->dispatch($input, [], self::actor());
            self::fail('Wire-time revocation reached transport');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('access_denied', $denied->reason);
            self::assertSame([], $history);
            self::assertSame(0, $service->usageRecords);
            self::assertFalse($egress->transport()->__debugInfo()['armed']);
        }
    }

    public function test_another_provider_instance_cannot_use_the_admission(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress);
        $snapshot = $service->snapshot([['role' => 'user', 'content' => 'Синтетический вопрос']], [], self::actor());

        $this->expectException(AssistantEgressDenied::class);
        $egress->transport()->arm(new TimewebProvider($this->createMock(LoggingService::class)), $snapshot, static fn () => null, 10);
    }

    public function test_the_guard_cannot_be_used_unarmed_after_an_approved_attempt(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority));
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));
        $service->dispatch($input, [], self::actor());
        [$messages, $options] = $service->prepared($input, [], self::actor());
        try {
            $service->provider()->responses($messages, $options);
            self::fail('A cleared permit was reused');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('access_denied', $denied->reason);
            self::assertCount(1, $history);
        }
    }

    public function test_http_retry_cannot_send_a_second_request_with_the_same_application_attempt(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority), responses: [new Response(503)]);
        $client = (new ReflectionProperty(TimewebProvider::class, 'httpClient'))->getValue($service->provider());
        $client->getConfig('handler')->push(Middleware::retry(static fn (int $retries): bool => $retries < 1, static fn (): int => 0));
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));
        try {
            $service->dispatch($input, [], self::actor());
            self::fail('An implicit HTTP retry reused the application admission');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('access_denied', $denied->reason);
            self::assertCount(1, $history);
            self::assertSame(1, $service->usageRecords);
        }
    }

    public function test_sdk_redirect_response_does_not_send_a_request_to_an_unknown_address(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress, responses: [new Response(307, ['Location' => 'https://unknown.invalid/collect'])]);
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, [], self::actor()));
        try {
            $service->dispatch($input, [], self::actor());
            self::fail('Redirect was accepted as a provider answer');
        } catch (\OpenAI\Exceptions\UnserializableResponse) {
            self::assertCount(1, $history);
            self::assertSame($authority->approvedScope['endpoint'], (string) $history[0]['request']->getUri());
            self::assertFalse($egress->transport()->__debugInfo()['armed']);
        }
    }

    public static function providerTimeouts(): array
    {
        return [['timeweb', 45.0, null, 45.0], ['timeweb', 45.0, 10.0, 10.0],
            ['openai', 45.0, null, 45.0], ['openai', 45.0, 10.0, 10.0]];
    }

    #[DataProvider('providerTimeouts')]
    public function test_real_transport_preserves_provider_and_per_attempt_timeouts(string $provider, float $configured, ?float $requested, float $expected): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $service = $this->service($history, new OrdinaryAssistantEgress($authority), provider: $provider);
        config(['ai-assistant.llm.'.$provider.'.timeout' => $configured]);
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $options = $requested === null ? [] : ['timeout' => $requested];
        $authority->approve($service->snapshot($input, $options, self::actor()));
        $service->dispatch($input, $options, self::actor());
        self::assertCount(1, $history);
        self::assertSame($expected, $history[0]['options']['timeout']);
        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function test_real_transport_clamps_timeout_to_the_remaining_execution_deadline(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress);
        $input = [['role' => 'user', 'content' => 'Синтетический вопрос']];
        $authority->approve($service->snapshot($input, ['timeout' => 45], self::actor()));
        [$messages, $options] = $service->prepared($input, ['timeout' => 45], self::actor());
        $request = $egress->prepare($messages, $options, 15, self::actor(), $service->provider()->getModel());
        $context = (new \ReflectionClass(AssistantRequestExecutionContext::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AssistantRequestExecutionContext::class, 'deadlineNanoseconds'))->setValue($context, hrtime(true) + 3_000_000_000);
        (new ReflectionProperty(AssistantRequestExecutionContext::class, 'lifecycleCheckpointInProgress'))->setValue($context, true);
        app()->instance(AssistantRequestExecutionContext::class, $context);
        $egress->transport()->arm($service->provider(), $request,
            fn () => $egress->assertCurrent($request, $messages, $options, 15, self::actor(), $service->provider()->getModel()), 45);
        try {
            $service->provider()->responses($messages, $options);
        } finally {
            $egress->transport()->clear();
        }
        self::assertCount(1, $history);
        self::assertGreaterThan(0, $history[0]['options']['timeout']);
        self::assertLessThanOrEqual(3, $history[0]['options']['timeout']);
        self::assertSame($history[0]['options']['timeout'], $history[0]['options']['connect_timeout']);
        self::assertIsCallable($history[0]['options']['progress']);
    }

    public static function changedBindings(): array
    {
        return array_map(static fn (string $case): array => [$case], ['nested-content', 'actor', 'organization', 'profile', 'route', 'tools']);
    }

    #[DataProvider('changedBindings')]
    public function test_changed_scope_profile_or_nested_content_invalidates_a_prepared_admission(string $case): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress);
        $actor = self::actor();
        [$messages, $options] = $service->prepared([['role' => 'user', 'content' => 'Синтетический вопрос']], [], $actor);
        $authority->approve(OrdinaryAssistantEgressRequest::capture($messages, $options, 15, $actor, 'openai/gpt-6-luna'));
        $request = $egress->prepare($messages, $options, 15, $actor, 'openai/gpt-6-luna');
        $organizationId = 15;
        match ($case) {
            'nested-content' => $messages[0]['content'] = self::CANARY,
            'actor' => $actor->id = 8,
            'organization' => [$organizationId, $actor->current_organization_id] = [16, 16],
            'profile' => $options['budget_profile'] = 'detailed',
            'route' => config(['ai-assistant.llm.timeweb.base_uri' => 'https://other.invalid/v1']),
            'tools' => $options['tools'] = [['type' => 'function', 'name' => 'lookup', 'description' => self::CANARY, 'parameters' => ['type' => 'object', 'properties' => []]]],
        };

        try {
            $egress->assertCurrent($request, $messages, $options, $organizationId, $actor, 'openai/gpt-6-luna');
            self::fail('Changed payload retained an old admission');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('source_stale', $denied->reason);
            self::assertSame([], $history);
        }
    }

    public function test_each_attempt_has_a_distinct_binding_and_missing_dependencies_do_not_fall_back(): void
    {
        $history = [];
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $service = $this->service($history, $egress);
        [$messages, $options] = $service->prepared([['role' => 'user', 'content' => 'Синтетический вопрос']], [], self::actor());
        $authority->approve(OrdinaryAssistantEgressRequest::capture($messages, $options, 15, self::actor(), 'openai/gpt-6-luna'));
        $first = $egress->prepare($messages, $options, 15, self::actor(), 'openai/gpt-6-luna');
        $next = $egress->prepare($messages, $options, 15, self::actor(), 'openai/gpt-6-luna');
        self::assertNotSame($first->binding(), $next->binding());
        self::assertSame(2, $authority->calls);
        $authority->onAuthorize = static fn () => throw new \RuntimeException(self::CANARY);

        try {
            $service->dispatch($messages, [], self::actor());
            self::fail('Unavailable authority fell back to raw provider');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('dependency_unavailable', $denied->reason);
            self::assertNull($denied->getPrevious());
            self::assertStringNotContainsString(self::CANARY, (string) $denied);
            self::assertSame([], $history);
        }
    }

    public function test_request_diagnostics_exclude_payload_and_runtime_serialization_is_forbidden(): void
    {
        $history = [];
        $service = $this->service($history);
        $snapshot = $service->snapshot([['role' => 'user', 'content' => self::CANARY]], [], self::actor());
        self::assertStringNotContainsString(self::CANARY, json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::CANARY, print_r($snapshot, true));
        $this->expectExceptionMessage('ordinary_assistant_egress_serialization_forbidden');
        serialize($snapshot);
    }

    private static function actor(): User
    {
        $actor = new User(['current_organization_id' => 15, 'is_active' => true]);
        $actor->id = 7;

        return $actor;
    }

    private function service(array &$history, ?OrdinaryAssistantEgress $egress = null, ?AssistantRequestLifecycle $lifecycle = null, array $responses = [], string $provider = 'timeweb'): OrdinaryEgressServiceHarness
    {
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://offline.invalid/v1', 'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna']);
        config(['ai-assistant.llm.provider' => $provider, 'ai-assistant.llm.openai.api_key' => 'offline-only-key',
            'ai-assistant.llm.openai.base_uri' => 'https://offline.invalid/v1', 'ai-assistant.llm.openai.model' => 'gpt-6-luna']);
        $wire = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'resp_offline', 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed', 'model' => $provider === 'timeweb' ? 'openai/gpt-6-luna' : 'gpt-6-luna',
            'output' => [['type' => 'message', 'id' => 'msg_offline', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Синтетический ответ.', 'annotations' => []]]]],
            'max_output_tokens' => 2048, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 20, 'total_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], JSON_THROW_ON_ERROR));
        $handler = Middleware::history($history)(new MockHandler($responses ?: [$wire]));
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context = []): void {
            self::assertStringNotContainsString(self::CANARY, $event.json_encode($context, JSON_THROW_ON_ERROR));
        });

        $registry = new AIToolRegistry;
        $permissions = $this->createMock(AIPermissionChecker::class);
        $egress ??= new OrdinaryAssistantEgress;

        return new OrdinaryEgressServiceHarness(
            (new OrdinaryAssistantProviderFactory($egress, $logging))->make($handler),
            $this->createMock(ConversationManager::class), $this->createMock(ContextBuilder::class),
            $this->createMock(IntentRecognizer::class), $this->createMock(UsageTracker::class), $logging,
            $registry, $permissions, $this->createMock(AssistantAccessContextResolver::class),
            $this->createMock(AssistantTaskOrchestrator::class), new AssistantAgentStateStore,
            new AssistantAgentPlanner(new AssistantCapabilityCatalog, new AssistantPeriodResolver),
            new AssistantAgentExecutor($registry, $permissions, new AssistantArtifactNormalizer), new AssistantResponseVerifier,
            requestLifecycle: $lifecycle,
            tokenBudget: new TokenBudgetService(new TokenCounter(new class
            {
                public function encode(string $text): array
                {
                    return array_fill(0, mb_strlen($text), 1);
                }
            })),
            ordinaryEgress: $egress,
        );
    }
}

final class OrdinaryEgressServiceHarness extends AIAssistantService
{
    public int $usageRecords = 0;

    public function provider(): LLMProviderInterface
    {
        return $this->llmProvider;
    }

    public function dispatch(array $input, array $options, User $actor): array
    {
        return $this->requestAssistantResponse($input, $options, 15, $actor);
    }

    public function prepared(array $input, array $options, User $actor): array
    {
        return $this->prepareProviderPayload($input, $options, 15, $actor);
    }

    public function snapshot(array $input, array $options, User $actor): OrdinaryAssistantEgressRequest
    {
        [$messages, $prepared] = $this->prepared($input, $options, $actor);

        return OrdinaryAssistantEgressRequest::capture($messages, $prepared, 15, $actor, $this->llmProvider->getModel());
    }

    protected function recordAssistantProviderUsage(array $response, int $organizationId, User $user, array $options, bool $degradedMode, bool $successful = true, ?int $attempt = null): void
    {
        $this->usageRecords++;
    }
}
