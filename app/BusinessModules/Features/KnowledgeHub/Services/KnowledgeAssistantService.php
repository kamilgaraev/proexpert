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

    public function answer(string $question, KnowledgeAccessContext $context, array $history = []): array
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 1000 || $context->userId === null) {
            throw new RuntimeException('knowledge_assistant_invalid_question');
        }

        $sources = $this->sources($context);
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

        $input = ['question' => $question, 'history' => array_slice($history, -8)];
        $selection = $this->complete('retrieve_prompt', $input + ['sources' => $sources], $context, 'knowledge_help_retrieval');
        $selected = $this->references($selection['source_ids'] ?? null, $sources);
        $clarification = $selection['clarification'] ?? null;
        if ($clarification !== null) {
            return $this->clarification($clarification);
        }
        if ($selected === []) {
            return $this->unknown();
        }

        $selectedIds = array_column($selected, 'id');
        $selectedSources = array_values(array_filter($sources, static fn (array $source): bool => in_array($source['id'], $selectedIds, true)));
        $response = $this->complete('prompt', $input + ['sources' => $selectedSources], $context, 'knowledge_help');
        $answer = $response['answer'] ?? null;
        if (! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 2000) {
            throw new RuntimeException('knowledge_assistant_invalid_response');
        }
        $references = $this->references($response['source_ids'] ?? null, $selectedSources);
        if (($response['needs_clarification'] ?? false) === true) {
            return $this->clarification($answer);
        }
        if ($references === []) {
            return $this->unknown();
        }

        return ['answer' => trim($answer), 'sources' => $references, 'status' => 'answered', 'needs_clarification' => false];
    }

    private function sources(KnowledgeAccessContext $context): array
    {
        $sources = [];
        $characters = 0;
        $page = 1;
        do {
            $articles = $this->knowledge->articles(['per_page' => 30, 'page' => $page], $context);
            if ($articles->total() > 120) {
                throw new RuntimeException('knowledge_assistant_catalog_limit');
            }
            foreach ($articles->getCollection() as $article) {
                $text = trim((string) $article->content_plain_text);
                if ($text === '') {
                    continue;
                }
                $characters += mb_strlen($text) + mb_strlen((string) $article->title);
                if ($characters > 160000) {
                    throw new RuntimeException('knowledge_assistant_catalog_limit');
                }
                $sources[] = ['id' => (int) $article->id, 'title' => (string) $article->title, 'text' => $text];
            }
            $page++;
        } while ($page <= $articles->lastPage());

        return $sources;
    }

    private function complete(string $prompt, array $input, KnowledgeAccessContext $context, string $operation): array
    {
        $response = $this->model->chat([
            ['role' => 'system', 'content' => trans_message('knowledge_assistant.'.$prompt)],
            ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], [
            'profile' => 'fast',
            'model' => 'dashscope/qwen3.5-flash',
            'enable_thinking' => false,
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
            $operation,
            (int) ($response['input_tokens'] ?? 0),
            (int) ($response['output_tokens'] ?? 0),
            (int) ($response['tokens_used'] ?? 0),
            ['surface' => $context->surface->value],
        );

        $decoded = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($decoded) || ($response['finish_reason'] ?? '') === 'length') {
            throw new RuntimeException('knowledge_assistant_invalid_response');
        }

        return $decoded;
    }

    private function references(mixed $ids, array $sources): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 6) {
            throw new RuntimeException('knowledge_assistant_invalid_sources');
        }
        $allowed = array_column($sources, 'title', 'id');
        $references = [];
        foreach ($ids as $id) {
            if (! is_int($id) || ! isset($allowed[$id])) {
                throw new RuntimeException('knowledge_assistant_invalid_sources');
            }
            $references[$id] = ['id' => $id, 'title' => $allowed[$id]];
        }

        return array_values($references);
    }

    private function clarification(mixed $question): array
    {
        if (! is_string($question) || trim($question) === '' || mb_strlen($question) > 2000) {
            throw new RuntimeException('knowledge_assistant_invalid_response');
        }

        return ['answer' => trim($question), 'sources' => [], 'status' => 'insufficient_knowledge', 'needs_clarification' => true];
    }

    private function unknown(): array
    {
        return [
            'answer' => trans_message('knowledge_assistant.unknown'),
            'sources' => [],
            'status' => 'insufficient_knowledge',
            'needs_clarification' => false,
        ];
    }
}
