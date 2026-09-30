<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebResponsesAdapter;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimewebResponsesAdapterTest extends TestCase
{
    public function test_preserves_optional_schema_and_canonical_strict_schema(): void
    {
        $function = ['name' => 'read_project', 'parameters' => ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer']], 'required' => []]];
        $payload = (new TimewebResponsesAdapter())->payload('openai/gpt-6-luna', [['role' => 'user', 'content' => 'Вопрос']], 128, [
            'tools' => [['type' => 'function', 'function' => $function], ['type' => 'function', 'function' => [...$function, 'strict' => true]]],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'read_project']],
            'enable_thinking' => true,
            'temperature' => 1,
            'previous_response_id' => 'resp_foreign',
            'store' => true,
        ]);

        self::assertSame($function['parameters'], $payload['tools'][0]['parameters']);
        self::assertFalse($payload['tools'][0]['strict']);
        self::assertTrue($payload['tools'][1]['strict']);
        self::assertSame(['type' => 'function', 'name' => 'read_project'], $payload['tool_choice']);
        self::assertFalse($payload['store']);
        foreach (['previous_response_id', 'temperature', 'enable_thinking', 'max_tokens', 'max_completion_tokens'] as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_json_schema_and_images_preserve_source_values(): void
    {
        $schema = ['name' => 'project_status', 'strict' => true, 'schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]];
        $image = 'data:image/png;base64,c3ludGhldGlj';
        $payload = (new TimewebResponsesAdapter())->payload('openai/gpt-6-luna', [
            ['role' => 'system', 'content' => 'Ответь JSON.'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Что на изображении?'], ['type' => 'image_url', 'image_url' => ['url' => $image, 'detail' => 'low']]]],
        ], 512, ['response_format' => ['type' => 'json_schema', 'json_schema' => $schema]]);

        self::assertSame(['type' => 'json_schema', ...$schema], $payload['text']['format']);
        self::assertSame([
            ['type' => 'input_text', 'text' => 'Что на изображении?'],
            ['type' => 'input_image', 'image_url' => $image, 'detail' => 'low'],
        ], $payload['input'][1]['content']);
        self::assertSame(512, $payload['max_output_tokens']);
    }

    public function test_preserves_text_preceding_multiple_calls_and_call_results(): void
    {
        $messages = [['role' => 'assistant', 'content' => 'Проверяю.', 'tool_calls' => [
            ['id' => 'call_one', 'function' => ['name' => 'one', 'arguments' => '{}']],
            ['id' => 'call_two', 'function' => ['name' => 'two', 'arguments' => '{"id":2}']],
        ]], ['role' => 'tool', 'tool_call_id' => 'call_one', 'content' => '{"ok":true}'], ['role' => 'tool', 'tool_call_id' => 'call_two', 'content' => 'Ошибка доступа']];
        $input = (new TimewebResponsesAdapter())->payload('openai/gpt-6-luna', $messages, 128, [])['input'];

        self::assertCount(5, $input);
        self::assertSame(['role' => 'assistant', 'content' => 'Проверяю.'], $input[0]);
        self::assertSame('call_one', $input[1]['call_id']);
        self::assertSame('{"id":2}', $input[2]['arguments']);
        self::assertSame(['type' => 'function_call_output', 'call_id' => 'call_one', 'output' => '{"ok":true}'], $input[3]);
        self::assertSame('Ошибка доступа', $input[4]['output']);
    }

    public function test_incomplete_response_retains_usage_without_executable_partial_calls(): void
    {
        $response = CreateResponse::fake([
            'model' => 'openai/gpt-6-luna', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'function_call', 'id' => 'fc_partial', 'call_id' => 'call_partial', 'name' => 'read_project', 'arguments' => '{"id":', 'status' => 'incomplete']],
            'usage' => ['input_tokens' => 80, 'output_tokens' => 128, 'total_tokens' => 208, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], strategy: OverrideStrategy::Replace);
        $result = (new TimewebResponsesAdapter())->result($response, 'openai/gpt-6-luna');

        self::assertSame('length', $result['finish_reason']);
        self::assertSame(208, $result['tokens_used']);
        self::assertSame(80, $result['input_tokens']);
        self::assertSame(128, $result['output_tokens']);
        self::assertSame('max_output_tokens', $result['incomplete_reason']);
        self::assertSame('incomplete', $result['response_status']);
        self::assertSame('', $result['content']);
        self::assertArrayNotHasKey('tool_calls', $result);
    }

    #[DataProvider('incompleteReasons')]
    public function test_incomplete_text_is_never_returned_and_actual_reason_is_preserved(string $reason, string $text): void
    {
        $response = CreateResponse::fake([
            'model' => 'openai/gpt-6-luna', 'status' => 'incomplete', 'incomplete_details' => ['reason' => $reason],
            'output' => [['type' => 'message', 'id' => 'msg_partial', 'role' => 'assistant', 'status' => 'incomplete', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        ], strategy: OverrideStrategy::Replace);
        $result = (new TimewebResponsesAdapter())->result($response, 'openai/gpt-6-luna');

        self::assertSame($reason, $result['incomplete_reason']);
        self::assertSame('', $result['content']);
        self::assertArrayNotHasKey('tool_calls', $result);
    }

    public static function incompleteReasons(): array
    {
        return [['max_output_tokens', 'Обрезанный ответ'], ['max_output_tokens', ''], ['content_filter', 'Обрезанный ответ']];
    }

    public function test_failed_response_is_rejected(): void
    {
        $response = CreateResponse::fake(['model' => 'openai/gpt-6-luna', 'status' => 'failed', 'output' => []], strategy: OverrideStrategy::Replace);
        $this->expectException(RuntimeException::class);
        (new TimewebResponsesAdapter())->result($response, 'openai/gpt-6-luna');
    }
}
