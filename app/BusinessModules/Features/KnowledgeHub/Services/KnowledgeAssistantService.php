<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class KnowledgeAssistantService
{
    public function __construct(
        private readonly KnowledgeHubQueryService $knowledge,
        private readonly LLMProviderInterface $model,
        private readonly UsageTracker $usage,
    ) {
    }

    public function answer(string $question, KnowledgeAccessContext $context): array
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 1000 || $context->userId === null) {
            throw new RuntimeException('knowledge_assistant_invalid_question');
        }

        preg_match_all('/[\p{L}\p{N}]{3,}/u', $question, $matches);
        $terms = array_slice(array_values(array_unique($matches[0])), 0, 12);
        if ($terms === []) {
            return $this->unknown();
        }

        $articles = $this->knowledge->articles([
            'q' => implode(' OR ', $terms),
            'per_page' => 6,
            'page' => 1,
        ], $context)->getCollection();

        $sources = [];
        foreach ($articles as $article) {
            $text = trim((string) $article->content_plain_text);
            if ($text === '') {
                continue;
            }
            $sources[] = [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'text' => mb_substr($text, 0, 5000),
            ];
        }
        if ($sources === []) {
            return $this->unknown();
        }
        if (! $this->model->isAvailable()) {
            throw new RuntimeException('knowledge_assistant_unavailable');
        }

        $attempts = RateLimiter::increment(
            'knowledge-assistant:monthly:'.now()->format('Y-m'),
            max(1, (int) now()->diffInSeconds(now()->endOfMonth()->addSecond())),
        );
        if ($attempts > max(1, (int) config('knowledge_assistant.monthly_request_limit', 2000))) {
            throw new RuntimeException('knowledge_assistant_limit');
        }

        return $this->generate($question, $sources, $context);
    }

    private function generate(string $question, array $sources, KnowledgeAccessContext $context): array
    {
        $response = $this->model->chat([
            ['role' => 'system', 'content' => trans_message('knowledge_assistant.prompt')],
            ['role' => 'user', 'content' => json_encode([
                'question' => $question,
                'sources' => $sources,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], [
            'profile' => 'fast',
            'max_tokens' => 900,
            'temperature' => 0.2,
            'timeout' => 20,
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->usage->recordUsage(
            $context->organizationId,
            $context->userId,
            (string) ($response['provider'] ?? ''),
            (string) ($response['model'] ?? $this->model->getModel()),
            'knowledge_help',
            (int) ($response['input_tokens'] ?? 0),
            (int) ($response['output_tokens'] ?? 0),
            (int) ($response['tokens_used'] ?? 0),
            ['surface' => $context->surface->value],
        );

        $decoded = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($decoded) || ($response['finish_reason'] ?? '') === 'length') {
            throw new RuntimeException('knowledge_assistant_invalid_response');
        }
        $answer = $decoded['answer'] ?? null;
        $ids = $decoded['source_ids'] ?? null;
        if (! is_string($answer) || ! is_array($ids) || trim($answer) === '' || mb_strlen($answer) > 2000) {
            throw new RuntimeException('knowledge_assistant_invalid_response');
        }
        if ($ids === []) {
            return $this->unknown();
        }
        $allowed = array_column($sources, 'title', 'id');
        $references = [];
        foreach (array_unique($ids, SORT_REGULAR) as $id) {
            if (! is_int($id) || ! isset($allowed[$id])) {
                throw new RuntimeException('knowledge_assistant_invalid_sources');
            }
            $references[] = ['id' => $id, 'title' => $allowed[$id]];
        }

        return [
            'answer' => trim($answer),
            'sources' => $references,
            'status' => 'answered',
        ];
    }

    private function unknown(): array
    {
        return [
            'answer' => trans_message('knowledge_assistant.unknown'),
            'sources' => [],
            'status' => 'insufficient_knowledge',
        ];
    }
}
