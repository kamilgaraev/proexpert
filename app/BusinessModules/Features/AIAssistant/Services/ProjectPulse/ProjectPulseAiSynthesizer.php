<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Collection;
use Throwable;

class ProjectPulseAiSynthesizer
{
    public function __construct(
        private readonly LLMProviderInterface $llmProvider,
        private readonly ProjectPulseRuleEngine $ruleEngine,
    ) {
    }

    public function synthesize(Collection $facts, Collection $ruleRecommendations, bool $useAi): array
    {
        $provider = config('ai-assistant.llm.provider', 'yandex');

        if (!$useAi || !config('ai-assistant.project_pulse.ai_enabled', true)) {
            return $this->rulesOnly($facts, $ruleRecommendations, 'ИИ-обобщение отключено в настройках или запросе.');
        }

        if (!$this->llmProvider->isAvailable()) {
            return $this->unavailable($facts, $ruleRecommendations, $provider);
        }

        try {
            $response = $this->llmProvider->chat([
                [
                    'role' => 'system',
                    'content' => 'Сформируй краткий управленческий отчет по строительным проектам. Верни только JSON с ключами summary.title, summary.text, recommendations.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'facts' => $facts->map->toArray()->values()->all(),
                        'recommendations' => $ruleRecommendations->map->toArray()->values()->all(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ], ['temperature' => 0.2]);

            $decoded = $this->decodeJson((string) ($response['content'] ?? ''));

            return [
                'ai_mode' => [
                    'status' => 'active',
                    'provider' => $provider,
                    'message' => 'Рекомендации усилены ИИ на основе фактов из системы.',
                ],
                'summary' => [
                    'title' => (string) data_get($decoded, 'summary.title', $this->ruleEngine->summary($facts)['title']),
                    'text' => (string) data_get($decoded, 'summary.text', $this->ruleEngine->summary($facts)['text']),
                ],
                'recommendations' => is_array($decoded['recommendations'] ?? null)
                    ? $this->normalizeAiRecommendations($decoded['recommendations'])
                    : $ruleRecommendations->map->toArray()->values()->all(),
            ];
        } catch (Throwable) {
            return $this->unavailable($facts, $ruleRecommendations, $provider);
        }
    }

    private function rulesOnly(Collection $facts, Collection $ruleRecommendations, string $message): array
    {
        return [
            'ai_mode' => [
                'status' => 'rules_only',
                'provider' => null,
                'message' => $message,
            ],
            'summary' => $this->ruleEngine->summary($facts),
            'recommendations' => $ruleRecommendations->map->toArray()->values()->all(),
        ];
    }

    private function unavailable(Collection $facts, Collection $ruleRecommendations, string $provider): array
    {
        return [
            'ai_mode' => [
                'status' => 'unavailable',
                'provider' => $provider,
                'message' => 'ИИ сейчас недоступен. Показаны системные факты и базовые рекомендации.',
            ],
            'summary' => $this->ruleEngine->summary($facts),
            'recommendations' => $ruleRecommendations->map->toArray()->values()->all(),
        ];
    }

    private function decodeJson(string $content): array
    {
        $content = trim(preg_replace('/^```json\s*|\s*```$/m', '', $content) ?? $content);
        $decoded = json_decode($content, true);

        if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Project Pulse AI response is not JSON.');
        }

        return $decoded;
    }

    private function normalizeAiRecommendations(array $recommendations): array
    {
        return collect($recommendations)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item, int $index) => [
                'id' => (string) ($item['id'] ?? 'ai:' . $index),
                'priority' => (string) ($item['priority'] ?? 'medium'),
                'title' => (string) ($item['title'] ?? 'Проверить рекомендацию'),
                'action' => (string) ($item['action'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
                'expected_effect' => (string) ($item['expected_effect'] ?? ''),
                'project_id' => isset($item['project_id']) ? (int) $item['project_id'] : null,
                'route' => $item['route'] ?? null,
                'source' => 'ai',
            ])
            ->values()
            ->all();
    }
}
