<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeRerankResultData;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use Throwable;

final class LLMNormativeCandidateReranker implements NormativeCandidateRerankerInterface
{
    public function __construct(
        private readonly LLMProviderInterface $llmProvider,
        private readonly RuleBasedNormativeCandidateReranker $fallback,
        private readonly ?bool $enabled = null,
        private readonly ?UsageTracker $usageTracker = null,
    ) {}

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $candidates
     */
    public function rerank(array $workItem, array $context, array $candidates): NormativeRerankResultData
    {
        if (!$this->enabled()) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_disabled']);
        }

        if (!$this->llmProvider->isAvailable()) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_unavailable']);
        }

        try {
            $response = $this->llmProvider->chat($this->messages($workItem, $context, $candidates), [
                'profile' => 'json',
                'temperature' => 0,
                'max_tokens' => 240,
            ]);
            $this->recordUsage($response, $context, count($candidates));
        } catch (Throwable) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_failed']);
        }

        $decoded = $this->decode((string) ($response['content'] ?? ''));
        if ($decoded === null) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_invalid_json']);
        }

        $selectedKey = $this->selectedKey($decoded);
        if ($selectedKey === null) {
            return new NormativeRerankResultData(
                selectedCandidateKey: null,
                confidence: $this->confidence($decoded['confidence'] ?? 0),
                reason: $this->reason($decoded['reason'] ?? 'llm_returned_none'),
                evidenceKeys: $this->stringList($decoded['evidence_keys'] ?? []),
                warnings: [],
                provider: 'llm',
            );
        }

        $candidate = $this->candidateByKey($selectedKey, $candidates);
        if ($candidate === null) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_unknown_candidate']);
        }

        if ($this->fallback->hardGated($candidate)) {
            return $this->fallback->rerank($workItem, $context, $candidates)
                ->withWarnings(['llm_reranker_hard_gated_candidate']);
        }

        return new NormativeRerankResultData(
            selectedCandidateKey: $selectedKey,
            confidence: $this->confidence($decoded['confidence'] ?? 0),
            reason: $this->reason($decoded['reason'] ?? 'llm_selected_candidate'),
            evidenceKeys: $this->stringList($decoded['evidence_keys'] ?? []),
            warnings: [],
            provider: 'llm',
        );
    }

    private function enabled(): bool
    {
        return $this->enabled ?? (bool) config('estimate-generation.normative_matching.reranker.llm_enabled', false);
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, string>>
     */
    private function messages(array $workItem, array $context, array $candidates): array
    {
        $payload = [
            'work_item' => [
                'name' => $workItem['name'] ?? null,
                'unit' => $workItem['unit'] ?? null,
                'quantity' => $workItem['quantity'] ?? null,
                'intent' => $workItem['work_intent'] ?? null,
            ],
            'context' => [
                'scope_type' => $context['scope_type'] ?? null,
                'section_title' => $context['section_title'] ?? null,
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'source_refs' => $context['source_refs'] ?? [],
            ],
            'candidates' => array_map(static fn (array $candidate): array => [
                'key' => $candidate['key'] ?? null,
                'code' => $candidate['code'] ?? null,
                'name' => $candidate['name'] ?? null,
                'unit' => $candidate['unit'] ?? null,
                'section' => $candidate['section'] ?? null,
                'confidence' => $candidate['confidence'] ?? null,
                'warnings' => $candidate['warnings'] ?? [],
                'learning_score' => $candidate['learning_score'] ?? 0,
                'learning_positive_count' => $candidate['learning_positive_count'] ?? 0,
                'learning_negative_count' => $candidate['learning_negative_count'] ?? 0,
                'work_composition' => array_slice($candidate['work_composition'] ?? [], 0, 8),
            ], array_slice($candidates, 0, $this->maxCandidates())),
        ];

        return [
            [
                'role' => 'system',
                'content' => 'Выбирай норму ФСНБ только из списка кандидатов. Если подходящей нормы нет, верни selected_candidate_key=null. Ответ строго JSON.',
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function maxCandidates(): int
    {
        try {
            return max(1, (int) config('estimate-generation.normative_matching.reranker.max_candidates', 8));
        } catch (Throwable) {
            return 8;
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $context
     */
    private function recordUsage(array $response, array $context, int $candidateCount): void
    {
        $inputTokens = max(0, (int) ($response['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($response['output_tokens'] ?? 0));
        $totalTokens = max(0, (int) ($response['tokens_used'] ?? ($inputTokens + $outputTokens)));

        if ($totalTokens <= 0 && $inputTokens <= 0 && $outputTokens <= 0) {
            return;
        }

        ($this->usageTracker ?? app(UsageTracker::class))->recordUsage(
            isset($context['organization_id']) ? (int) $context['organization_id'] : null,
            isset($context['user_id']) ? (int) $context['user_id'] : null,
            (string) ($response['provider'] ?? config('ai-assistant.llm.provider', 'unknown')),
            (string) ($response['model'] ?? $this->llmProvider->getModel()),
            'estimate_generation_rerank',
            $inputTokens,
            $outputTokens,
            $totalTokens,
            [
                'project_id' => isset($context['project_id']) ? (int) $context['project_id'] : null,
                'scope_type' => $context['scope_type'] ?? null,
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'section_title' => $context['section_title'] ?? null,
                'candidate_count' => $candidateCount,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $content): ?array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function selectedKey(array $decoded): ?string
    {
        $value = $decoded['selected_candidate_key'] ?? $decoded['selectedCandidateKey'] ?? null;

        if ($value === null || $value === '' || $value === 'none') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function candidateByKey(string $selectedKey, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ((string) ($candidate['key'] ?? '') === $selectedKey) {
                return $candidate;
            }
        }

        return null;
    }

    private function confidence(mixed $value): float
    {
        return round(min(0.95, max(0.0, is_numeric($value) ? (float) $value : 0.0)), 4);
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);

        return $reason !== '' ? mb_substr($reason, 0, 500) : 'llm_rerank';
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
