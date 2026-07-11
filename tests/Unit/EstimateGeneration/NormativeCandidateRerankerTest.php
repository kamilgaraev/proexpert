<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\RuleBasedNormativeCandidateReranker;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateRerankerTest extends TestCase
{
    public function test_rule_based_reranker_selects_candidate_key_from_provided_list(): void
    {
        $result = $this->ruleBasedReranker()->rerank(
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
        $result = $this->ruleBasedReranker()->rerank(
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

    public function test_rule_based_reranker_hard_gates_partially_priced_candidate(): void
    {
        $result = $this->ruleBasedReranker()->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            ['scope_type' => 'foundation'],
            [
                $this->candidate('candidate-partial', [
                    'warnings' => ['norm_with_unpriced_resources'],
                    'score' => 900,
                    'learning_score' => 80,
                    'resources' => [
                        'materials' => [
                            ['price_source' => 'fsbc_base'],
                            ['price_source' => null],
                        ],
                        'machinery' => [],
                        'labor' => [],
                        'other' => [],
                    ],
                ]),
                $this->candidate('candidate-priced', ['score' => 20]),
            ]
        );

        $this->assertSame('candidate-priced', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
    }

    public function test_rule_based_reranker_hard_gates_zero_price_candidate_without_warning(): void
    {
        $result = $this->ruleBasedReranker()->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            ['scope_type' => 'foundation'],
            [
                $this->candidate('candidate-zero-price', [
                    'score' => 900,
                    'learning_score' => 80,
                    'resources' => [
                        'materials' => [[
                            'price_source' => 'fsbc_base',
                            'quantity' => 1,
                            'unit_price' => 0,
                            'total_price' => 0,
                        ]],
                        'machinery' => [],
                        'labor' => [],
                        'other' => [],
                    ],
                ]),
                $this->candidate('candidate-priced', ['score' => 20]),
            ]
        );

        $this->assertSame('candidate-priced', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
    }

    public function test_rule_based_reranker_rejects_wrong_domain_even_with_higher_score(): void
    {
        $result = $this->ruleBasedReranker()->rerank(
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
        $reranker = $this->llmReranker('{"selected_candidate_key":"ghost","confidence":0.9,"reason":"x","evidence_keys":[]}');

        $result = $reranker->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            $this->paidContext(),
            [$this->candidate('candidate-a', ['score' => 60])]
        );

        $this->assertSame('candidate-a', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_unknown_candidate', $result->warnings);
    }

    public function test_llm_reranker_cannot_select_hard_gated_candidate(): void
    {
        $reranker = $this->llmReranker('{"selected_candidate_key":"candidate-a","confidence":0.95,"reason":"x","evidence_keys":[]}');

        $result = $reranker->rerank(
            ['name' => 'Опалубка ленточного фундамента', 'unit' => 'м2'],
            $this->paidContext(),
            [
                $this->candidate('candidate-a', ['warnings' => ['unit_mismatch'], 'score' => 100]),
                $this->candidate('candidate-b', ['score' => 40]),
            ]
        );

        $this->assertSame('candidate-b', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_hard_gated_candidate', $result->warnings);
    }

    public function test_llm_reranker_cannot_select_partially_priced_candidate(): void
    {
        $reranker = $this->llmReranker('{"selected_candidate_key":"candidate-partial","confidence":0.95,"reason":"x","evidence_keys":[]}');

        $result = $reranker->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            $this->paidContext(),
            [
                $this->candidate('candidate-partial', [
                    'warnings' => ['norm_with_unpriced_resources'],
                    'score' => 900,
                    'resources' => [
                        'materials' => [
                            ['price_source' => 'fsbc_base'],
                            ['price_source' => null],
                        ],
                        'machinery' => [],
                        'labor' => [],
                        'other' => [],
                    ],
                ]),
                $this->candidate('candidate-priced', ['score' => 40]),
            ]
        );

        $this->assertSame('candidate-priced', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_hard_gated_candidate', $result->warnings);
    }

    public function test_invalid_llm_json_returns_rule_based_result_with_warning(): void
    {
        $reranker = $this->llmReranker('не json');

        $result = $reranker->rerank(
            ['name' => 'Бетонирование фундаментной ленты', 'unit' => 'м3'],
            $this->paidContext(),
            [$this->candidate('candidate-a', ['score' => 60])]
        );

        $this->assertSame('candidate-a', $result->selectedCandidateKey);
        $this->assertSame('rule_based', $result->provider);
        $this->assertContains('llm_reranker_invalid_json', $result->warnings);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ruleBasedReranker(): RuleBasedNormativeCandidateReranker
    {
        return new RuleBasedNormativeCandidateReranker;
    }

    private function llmReranker(string $content): LLMNormativeCandidateReranker
    {
        $provider = new FakeNormativeRerankerLlm($content);
        $wire = new class($provider) implements RerankWireClient
        {
            public function __construct(private FakeNormativeRerankerLlm $provider) {}

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                return $this->provider->chat($messages, $options);
            }
        };
        $store = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void {}
        };

        return new LLMNormativeCandidateReranker($provider, $this->ruleBasedReranker(), true,
            new AttemptAwareNormativeLlmClient($wire, $store, [$provider->getModel()], []));
    }

    /** @return array<string, mixed> */
    private function paidContext(): array
    {
        return ['scope_type' => 'foundation', 'organization_id' => 1, 'project_id' => 2, 'session_id' => 3,
            'checkpoint_claim_token' => '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'input_version' => 'sha256:abc',
            'work_item_key' => 'work-1', 'logical_attempt' => 1];
    }

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
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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
