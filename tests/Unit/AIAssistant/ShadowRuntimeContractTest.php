<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionClass;
use Tests\Support\ShadowConversationScenario;
use Tests\Support\ShadowProviderErrorScenario;
use Tests\Support\ShadowScenarioManifest;

final class ShadowRuntimeContractTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_scoped_provider_and_main_instances_are_restored_after_an_exception(): void
    {
        $provider = $this->createMock(LLMProviderInterface::class);
        $binding = static fn (): LLMProviderInterface => $provider;
        app()->singleton(LLMProviderInterface::class, $binding);
        $main = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        app()->instance(AIAssistantService::class, $main);
        try {
            ShadowProviderErrorScenario::withLimitedProvider(function () use ($provider): void {
                self::assertNotSame($provider, app(LLMProviderInterface::class));
                self::assertFalse(app()->resolved(AIAssistantService::class));
                throw new RuntimeException('failed scenario');
            });
            self::fail('The actual scenario exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('failed scenario', $exception->getMessage());
        }
        self::assertSame($provider, app(LLMProviderInterface::class));
        self::assertSame($main, app(AIAssistantService::class));
        self::assertSame($binding, app()->getBindings()[LLMProviderInterface::class]['concrete']);
    }
    public function test_full_manifest_has_distinct_inputs_and_a_last_real_failure_contract(): void
    {
        $manifest = ShadowScenarioManifest::load(false, true);
        self::assertCount(389, $manifest);
        self::assertCount(389, array_unique(array_column($manifest, 'id')));
        self::assertSame('provider-incomplete-real-receipt', $manifest[388]['id']);
        self::assertSame('errors', $manifest[388]['category']);
        self::assertSame('real_incomplete_provider_receipt', $manifest[388]['input']['context']['coverage_contract']);
        self::assertSame(378, count(ShadowScenarioManifest::load()));
    }

    public function test_passthrough_limits_only_output_and_preserves_actual_delegate_result_and_failure(): void
    {
        $actual = ['input_tokens' => 42, 'output_tokens' => 1, 'provider_usage_available' => true];
        $provider = new class($actual) implements LLMProviderInterface {
            public array $messages = [];
            public array $options = [];
            public bool $fail = false;
            public function __construct(private readonly array $result) {}
            public function chat(array $messages, array $options = []): array
            {
                $this->messages = $messages;
                $this->options = $options;
                if ($this->fail) { throw new RuntimeException('actual delegate exception'); }
                return $this->result;
            }
            public function countTokens(string $text): int { return strlen($text); }
            public function isAvailable(): bool { return true; }
            public function getModel(): string { return 'openai/gpt-6-luna'; }
        };
        $limited = ShadowProviderErrorScenario::limitedProvider($provider);
        $messages = [['role' => 'user', 'content' => 'Real request']];
        self::assertSame($actual, $limited->chat($messages, ['max_completion_tokens' => 2048, 'tools' => []]));
        self::assertSame($messages, $provider->messages);
        self::assertSame(['max_completion_tokens' => 1, 'tools' => []], $provider->options);
        self::assertSame(3, $limited->countTokens('abc'));
        self::assertTrue($limited->isAvailable());
        self::assertSame('openai/gpt-6-luna', $limited->getModel());
        $provider->fail = true;
        $this->expectExceptionMessage('actual delegate exception');
        $limited->chat($messages);
    }

    #[DataProvider('failedReceiptCases')]
    public function test_failure_oracle_requires_raw_usage_matching_journal_and_released_user_balance(string $defect): void
    {
        $raw = json_encode(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['input_tokens' => 100, 'output_tokens' => 1]], JSON_THROW_ON_ERROR);
        $call = ['kind' => 'assistant', 'success' => false, 'raw_provider_response' => $raw, 'usage_source' => 'provider_response',
            'evidence' => 'provider_usage', 'input_tokens' => 100, 'output_tokens' => 1, 'cost_micro_rub' => 1418, 'provider_evidence_sha256' => hash('sha256', $raw)];
        $row = ['usage_key' => 'request:call:1', 'is_successful' => false, 'cost_micro_rub' => 1418,
            'metadata' => ['provider_usage_available' => true, 'cost_available' => true, 'input_tokens' => 100, 'output_tokens' => 1]];
        $after = $before = ['available_minor' => 5000, 'reserved_minor' => 0];
        $published = 0;
        if ($defect === 'unknown_usage') { $call['usage_source'] = 'unverified'; }
        if ($defect === 'fake_hash') { $call['provider_evidence_sha256'] = str_repeat('0', 64); }
        if ($defect === 'wrong_tokens') { $row['metadata']['input_tokens'] = 101; }
        if ($defect === 'wrong_cost') { $row['cost_micro_rub'] = 0; }
        if ($defect === 'unlinked') { $row['usage_key'] = 'other:call:1'; }
        if ($defect === 'published') { $published = 1; }
        if ($defect === 'debited') { $after['available_minor'] = 4950; }
        $checks = ShadowProviderErrorScenario::receiptChecks([$call], [$row], 'request', 'failed', 'cancelled', 0, $published, $before, $after, true, true);
        self::assertSame($defect !== '', in_array(false, $checks, true));
    }

    public static function failedReceiptCases(): array
    {
        return array_map(static fn (string $defect): array => [$defect], ['', 'unknown_usage', 'fake_hash', 'wrong_tokens', 'wrong_cost', 'unlinked', 'published', 'debited']);
    }

    public function test_context_oracle_uses_server_project_identity_current_acl_and_exact_saved_query(): void
    {
        $response = ['message' => ['content' => 'Выбран проект «МОСТ QA»: идентификатор 17.', 'metadata' => ['validation_status' => 'verified']]];
        $golden = ['id' => 17, 'name' => 'МОСТ QA'];
        self::assertNotContains(false, ShadowConversationScenario::projectContextChecks($response, $golden, true, true, true));
        self::assertContains(false, ShadowConversationScenario::projectContextChecks($response, ['id' => 18, 'name' => 'МОСТ QA'], true, true, true));
        self::assertContains(false, ShadowConversationScenario::projectContextChecks($response, $golden, false, true, true));
        self::assertContains(false, ShadowConversationScenario::projectContextChecks($response, $golden, true, true, false));
        self::assertContains(false, ShadowConversationScenario::projectContextChecks($response, ['id' => 17, 'name' => 'Иной проект'], true, true, true));
    }

    #[DataProvider('financialCases')]
    public function test_nonfinancial_answer_rejects_monetary_claims_without_rejecting_project_ids(array $response, bool $allowed): void
    {
        self::assertSame($allowed, ShadowConversationScenario::nonFinancialAnswer($response));
    }

    public static function financialCases(): array
    {
        return [
            [['message' => ['content' => 'Проект 42, МОСТ QA', 'metadata' => ['validation_status' => 'verified']]], true],
            [['answer' => 'Сумма: 1500'], false], [['answer' => 'Цена 12.34 RUB'], false],
            [['answer' => 'Бюджет проекта составляет 1500'], false],
            [['answer' => 'Проект 42', 'financial_claims' => [['amount' => 100]]], false], [['answer' => ''], false],
        ];
    }
}
