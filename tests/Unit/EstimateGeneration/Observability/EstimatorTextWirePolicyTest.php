<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class EstimatorTextWirePolicyTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['ai-assistant-credits' => ['profiles' => ['normal' => ['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4]]]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_runtime_profile_changes_do_not_change_the_approved_completion_limit(): void
    {
        $policy = EstimatorTextWirePolicy::capture();
        config()->set('ai-assistant-credits.profiles.normal', ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6]);
        $payload = (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna',
            [['role' => 'user', 'content' => 'Уточните состав работ.']],
            ['profile' => 'json', 'max_tokens' => 8192, 'estimate_generation_frozen_text_policy' => $policy]);
        self::assertSame(2048, $payload['max_completion_tokens']);
    }

    public function test_serialized_size_is_checked_separately_from_tokens_without_shortening_source_content(): void
    {
        $policy = EstimatorTextWirePolicy::capture();
        $messages = [['role' => 'user', 'content' => str_repeat('word ', 14000)]];
        self::assertGreaterThan(65536, EstimatorTextWirePolicy::serializedInputUpper($messages));
        $this->expectExceptionMessage('estimate_text_serialized_input_requires_source_split');
        (new TimewebChatCompletionPayloadFactory)->make('openai/gpt-6-luna', $messages,
            ['profile' => 'json', 'max_tokens' => 2048, 'estimate_generation_frozen_text_policy' => $policy]);
    }

    public function test_chunk_preflight_checks_escaped_json_and_system_headroom_against_the_same_byte_limit(): void
    {
        $policy = [...EstimatorTextWirePolicy::capture(), 'maximum_serialized_input_bytes' => 12000];
        self::assertTrue((new EstimatorTextPayloadBudget)->fits('Small source', $policy));
        self::assertFalse((new EstimatorTextPayloadBudget)->fits(str_repeat('word ', 2000), $policy));
    }

    public function test_historical_policy_without_a_view_marker_keeps_its_exact_shape_and_limits(): void
    {
        $legacy = EstimatorTextWirePolicy::legacyCapture();
        self::assertArrayNotHasKey('audit_draft_view', $legacy);
        self::assertSame($legacy, EstimatorTextWirePolicy::validate($legacy));
        self::assertSame('business:v1', EstimatorTextWirePolicy::capture()['audit_draft_view']);
        self::assertSame(65536, EstimatorTextWirePolicy::forInputCeiling($legacy, 524288)['maximum_serialized_input_bytes']);
        $this->expectExceptionMessage('estimator_text_wire_policy_invalid');
        EstimatorTextWirePolicy::validate([...$legacy, 'audit_draft_view' => 'unknown']);
    }
}
