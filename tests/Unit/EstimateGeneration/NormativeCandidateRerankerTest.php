<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\RuleBasedNormativeCandidateReranker;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Tests\TestCase;

final class NormativeCandidateRerankerTest extends TestCase
{
    public function test_rule_based_reranker_selects_candidate_key_from_provided_list(): void
    {
        $result = app(RuleBasedNormativeCandidateReranker::class)->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            ['scope_type' => 'foundation'],
            [
                $this->candidate('candidate-a', ['score' => 40, 'learning_score' => 0]),
                $this->candidate('candidate-b', ['score' => 35, 'learning_score' => 18]),
            ]
        );

        $this->assertSame('candidate-b', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('learning_score', $result->evidenceKeys);
    }

    public function test_rule_based_reranker_returns_none_when_all_candidates_are_hard_gated(): void
    {
        $result = app(RuleBasedNormativeCandidateReranker::class)->rerank(
            ['name' => 'Опалубка ленточного фундамента', 'unit' => 'м2'],
            ['scope_type' => 'foundation'],
            [
                $this->candidate('candidate-a', ['warnings' => ['unit_mismatch'], 'score' => 100]),
                $this->candidate('candidate-b', ['warnings' => ['scope_mismatch'], 'score' => 80]),
            ]
        );

        $this->assertNull($result->selectedCandidateKey);
        $this->assertContains('all_candidates_hard_gated', $result->warnings);
    }

    public function test_rule_based_reranker_rejects_wrong_domain_even_with_higher_score(): void
    {
        $result = app(RuleBasedNormativeCandidateReranker::class)->rerank(
            ['name' => 'Воздушно-тепловые завесы ворот', 'unit' => 'шт'],
            ['scope_type' => 'engineering', 'section_title' => 'Отопление'],
            [
                $this->candidate('candidate-crane', [
                    'code' => '09-05-001-01',
                    'name' => 'Кран портальный электрический',
                    'unit' => 'шт',
                    'score' => 140,
                    'section' => ['code' => '09', 'name' => 'Металлические конструкции'],
                ]),
                $this->candidate('candidate-heating', [
                    'code' => '18-03-001-01',
                    'name' => 'Установка воздушно-тепловых завес',
                    'unit' => 'шт',
                    'score' => 40,
                    'section' => ['code' => '18', 'name' => 'Отопление'],
                ]),
            ]
        );

        $this->assertSame('candidate-heating', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
    }

    public function test_llm_reranker_cannot_introduce_candidate_not_in_list(): void
    {
        $reranker = new LLMNormativeCandidateReranker(
            new FakeNormativeRerankerLlm('{"selected_candidate_key":"ghost","confidence":0.9,"reason":"x","evidence_keys":[]}'),
            app(RuleBasedNormativeCandidateReranker::class),
            true
        );

        $result = $reranker->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            ['scope_type' => 'foundation'],
            [$this->candidate('candidate-a', ['score' => 60])]
        );

        $this->assertSame('candidate-a', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_unknown_candidate', $result->warnings);
    }

    public function test_llm_reranker_cannot_select_hard_gated_candidate(): void
    {
        $reranker = new LLMNormativeCandidateReranker(
            new FakeNormativeRerankerLlm('{"selected_candidate_key":"candidate-a","confidence":0.95,"reason":"x","evidence_keys":[]}'),
            app(RuleBasedNormativeCandidateReranker::class),
            true
        );

        $result = $reranker->rerank(
            ['name' => 'Опалубка ленточного фундамента', 'unit' => 'м2'],
            ['scope_type' => 'foundation'],
            [
                $this->candidate('candidate-a', ['warnings' => ['unit_mismatch'], 'score' => 100]),
                $this->candidate('candidate-b', ['score' => 40]),
            ]
        );

        $this->assertSame('candidate-b', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_hard_gated_candidate', $result->warnings);
    }

    public function test_invalid_llm_json_returns_rule_based_result_with_warning(): void
    {
        $reranker = new LLMNormativeCandidateReranker(
            new FakeNormativeRerankerLlm('не json'),
            app(RuleBasedNormativeCandidateReranker::class),
            true
        );

        $result = $reranker->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            ['scope_type' => 'foundation'],
            [$this->candidate('candidate-a', ['score' => 60])]
        );

        $this->assertSame('candidate-a', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_invalid_json', $result->warnings);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function candidate(string $key, array $overrides = []): array
    {
        return [
            'key' => $key,
            'code' => '01-01-001-01',
            'name' => 'Бетонирование фундаментной ленты',
            'unit' => 'м3',
            'score' => 50,
            'confidence' => 0.75,
            'warnings' => [],
            'match_reasons' => [],
            'learning_score' => 0,
            'learning_positive_count' => 0,
            'learning_negative_count' => 0,
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
            ...$overrides,
        ];
    }
}

final class FakeNormativeRerankerLlm implements LLMProviderInterface
{
    public function __construct(
        private readonly string $content,
    ) {}

    public function chat(array $messages, array $options = []): array
    {
        return ['content' => $this->content];
    }

    public function countTokens(string $text): int
    {
        return mb_strlen($text);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return 'fake';
    }
}
