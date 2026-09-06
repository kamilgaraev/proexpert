<?php

declare(strict_types=1);

namespace Tests\Unit\KnowledgeHub;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAssistantService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubQueryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use RuntimeException;
use Tests\Support\DatabaseLessTestCase;

final class KnowledgeAssistantServiceTest extends DatabaseLessTestCase
{
    public function test_missing_sources_do_not_call_the_model(): void
    {
        $query = Mockery::mock(KnowledgeHubQueryService::class);
        $query->shouldReceive('articles')->once()->andReturn(new LengthAwarePaginator([], 0, 6));
        $model = Mockery::mock(LLMProviderInterface::class);
        $model->shouldNotReceive('chat');
        $usage = Mockery::mock(UsageTracker::class);
        $usage->shouldNotReceive('recordUsage');

        $result = (new KnowledgeAssistantService($query, $model, $usage))->answer('Как войти?', $this->context());

        self::assertSame('insufficient_knowledge', $result['status']);
        self::assertSame([], $result['sources']);
    }

    public function test_model_cannot_cite_an_article_outside_the_retrieved_sources(): void
    {
        $service = $this->serviceWithResponse('{"answer":"Откройте вход","source_ids":[999]}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('knowledge_assistant_invalid_sources');

        $service->answer('Как войти?', $this->context());
    }

    public function test_valid_answer_contains_only_source_identifiers_and_titles(): void
    {
        $service = $this->serviceWithResponse('{"answer":"Введите почту и пароль.","source_ids":[4]}');

        $result = $service->answer('Как войти?', $this->context());

        self::assertSame('answered', $result['status']);
        self::assertSame('Введите почту и пароль.', $result['answer']);
        self::assertSame([['id' => 4, 'title' => 'Вход']], $result['sources']);
    }

    public function test_invalid_json_is_not_returned_to_the_user(): void
    {
        $service = $this->serviceWithResponse('<script>invalid</script>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('knowledge_assistant_invalid_response');

        $service->answer('Как войти?', $this->context());
    }

    private function serviceWithResponse(string $content): KnowledgeAssistantService
    {
        $query = Mockery::mock(KnowledgeHubQueryService::class);
        $query->shouldReceive('articles')->once()->andReturn(new LengthAwarePaginator([
            (object) ['id' => 4, 'title' => 'Вход', 'content_plain_text' => 'Введите почту и пароль.'],
        ], 1, 6));
        $model = Mockery::mock(LLMProviderInterface::class);
        $model->shouldReceive('isAvailable')->once()->andReturnTrue();
        $model->shouldReceive('getModel')->andReturn('test');
        $model->shouldReceive('chat')->once()->withArgs(function (array $messages, array $options): bool {
            $input = json_decode($messages[1]['content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([4], array_column($input['sources'], 'id'));
            self::assertSame(900, $options['max_tokens']);
            self::assertArrayNotHasKey('tools', $options);

            return true;
        })->andReturn([
            'content' => $content,
            'provider' => 'test',
            'model' => 'test',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'tokens_used' => 15,
            'finish_reason' => 'stop',
        ]);
        $usage = Mockery::mock(UsageTracker::class);
        $usage->shouldReceive('recordUsage')->once()->andReturnNull();
        RateLimiter::shouldReceive('increment')->once()->andReturn(1);

        return new KnowledgeAssistantService($query, $model, $usage);
    }

    private function context(): KnowledgeAccessContext
    {
        return new KnowledgeAccessContext(KnowledgeSurface::ADMIN, ['all'], [], [], null, null, null, 1, 1);
    }
}
