<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantChatRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantCreditQuoteRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class AssistantClarificationContinuationContractTest extends TestCase
{
    public function test_chat_and_quote_accept_the_explicit_closed_continuation_envelope(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $payload = ['message' => 'За октябрь.', 'profile' => 'short', 'conversation_id' => 52,
            'request_id' => '6b44493c-27c0-4c6c-9b75-26d93147ed33', 'quote_id' => '47fa0ad7-de12-40c1-94d9-194811736283',
            'context' => ['clarification_continuation' => ['version' => 'ordinary-clarification-context/1',
                'parent_request_id' => '13ac7766-f3dd-4769-b4e8-61adc1d90643', 'context_hash' => str_repeat('a', 64)]]];
        foreach ([new AssistantChatRequest, new AssistantCreditQuoteRequest] as $request) {
            $validation = $factory->make($payload, $request->rules());
            self::assertTrue($validation->passes(), json_encode($validation->errors()->toArray(), JSON_THROW_ON_ERROR));
            $tampered = $payload;
            $tampered['context']['clarification_continuation']['previous_answer'] = 'Client supplied history';
            self::assertFalse($factory->make($tampered, $request->rules())->passes());
        }
    }
    public function test_parent_question_bounds_reject_instead_of_truncating(): void
    {
        $class = \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantClarificationContext::class;
        self::assertTrue($class::questionAllowed(str_repeat('я', 2000)));
        self::assertTrue($class::questionAllowed(str_repeat('🧱', 2000)));
        self::assertFalse($class::questionAllowed(str_repeat('я', 2001)));
        self::assertFalse($class::questionAllowed(str_repeat('🧱', 2001)));
        self::assertTrue($class::replyAllowed(str_repeat('🧱', 4000)));
        self::assertFalse($class::replyAllowed(str_repeat('🧱', 4001)));
        self::assertFalse($class::questionAllowed(' '));
        self::assertFalse($class::questionAllowed("\xff"));
    }

    public function test_context_envelope_is_closed_and_old_manifests_keep_their_digest(): void
    {
        $class = \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantClarificationContext::class;
        $value = ['version' => $class::VERSION, 'parent_request_id' => '13ac7766-f3dd-4769-b4e8-61adc1d90643', 'context_hash' => str_repeat('a', 64)];
        self::assertNull($class::envelope(['context' => ['ui_state' => ['previous_answer' => 'not admitted']]]));
        self::assertSame($value, $class::envelope(['context' => [$class::KEY => array_reverse($value, true)]]));
        $binding = ['cohort' => 'same', 'sources' => [], 'input_hash' => 'input'];
        self::assertSame(\App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection::hash($binding), $class::manifestHash($binding, null));
        self::assertNotSame($class::manifestHash($binding, null), $class::manifestHash($binding, $value));
        foreach ([$value + ['history' => []], array_replace($value, ['version' => 'unknown']), array_replace($value, ['context_hash' => 'bad'])] as $invalid) {
            try { $class::envelope(['context' => [$class::KEY => $invalid]]); self::fail('Untrusted continuation field passed'); }
            catch (\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied $denied) { self::assertSame('assistant_egress_denied', $denied->getMessage()); }
        }
    }
}
