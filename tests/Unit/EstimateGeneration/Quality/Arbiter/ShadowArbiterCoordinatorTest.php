<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterReviewContextFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdictValidator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\CompletenessArbiter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ShadowArbiterCoordinator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShadowArbiterCoordinatorTest extends TestCase
{
    #[Test]
    public function it_preserves_every_work_item_and_records_only_a_shadow_recommendation(): void
    {
        $draft = [
            'source_input_version' => 'input-v1',
            'completeness' => [
                'status' => 'confirmed_scope_only',
                'scopes' => [[
                    'key' => 'heating',
                    'state' => 'unresolved',
                    'evidence_refs' => ['evidence:1'],
                ]],
            ],
            'budget_scope' => ['direct_costs' => 1200.0],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'name' => 'Unchanged work item',
                    'metadata' => ['composition_work_key' => 'heating.unit'],
                ]]]],
            ]],
        ];
        $arbiter = new class implements CompletenessArbiter
        {
            public function review(array $context): array
            {
                return [
                    'outcome' => 'targeted_rebuild',
                    'input_tokens' => 123,
                    'output_tokens' => 45,
                    'findings' => [[
                        'scope_key' => 'heating',
                        'package_keys' => ['heating'],
                        'evidence_refs' => ['evidence:1'],
                        'action' => 'rebuild',
                        'reason_code' => 'missing_component',
                    ]],
                ];
            }

            public function model(): string
            {
                return 'openai/gpt-5-mini';
            }

            public function promptVersion(): string
            {
                return 'completeness-arbiter:v1';
            }
        };

        $reviewed = (new ShadowArbiterCoordinator(
            $arbiter,
            new ArbiterReviewContextFactory,
            new ArbiterVerdictValidator,
        ))->review($draft);

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame((new ArbiterReviewContextFactory)->make($draft)['input_hash'], $reviewed['arbiter_review']['cycle']['input_hash']);
        self::assertSame('shadow', $reviewed['arbiter_review']['mode']);
        self::assertSame('targeted_rebuild', $reviewed['arbiter_review']['outcome']);
        self::assertSame('openai/gpt-5-mini', $reviewed['arbiter_review']['model']);
        self::assertSame('completeness-arbiter:v1', $reviewed['arbiter_review']['prompt_version']);
        self::assertSame(123, $reviewed['arbiter_review']['input_tokens']);
        self::assertSame(45, $reviewed['arbiter_review']['output_tokens']);
        self::assertArrayNotHasKey('context', $reviewed['arbiter_review']);
    }
}
