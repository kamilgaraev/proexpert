<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceIdentityQueryBuilder;
use PHPUnit\Framework\TestCase;

final class AssistantSourceIdentityQueryBuilderTest extends TestCase
{
    public function test_counter_plans_partition_entities_without_losing_source_pairs(): void
    {
        $identities = [];
        for ($entity = 1; $entity <= 9; $entity++) {
            $identities['entity_'.$entity] = ['organization_reporting' => true];
        }
        $plans = (new AssistantSourceIdentityQueryBuilder)->planBatches($identities, ['organization_reporting' => 20000], 8, false, boundSourceIdentities: true);

        self::assertCount(2, $plans);
        self::assertSame([8, 1], array_map(static fn (array $plan): int => count($plan['entities']), $plans));
        self::assertSame(array_keys($identities), array_merge(...array_column($plans, 'entities')));
        foreach ($plans as $plan) {
            self::assertSame(['organization_reporting'], $plan['types']);
            self::assertNull($plan['entity']);
        }
    }

    public function test_counter_plans_weight_each_alias_and_keep_source_row_batches(): void
    {
        $identities = [
            'first' => ['core_business' => true, 'budgeting' => true, 'project' => true],
            'second' => ['core_business' => true, 'budgeting' => true, 'project' => true],
            'assistant_document' => ['file_document' => true],
        ];
        $plans = (new AssistantSourceIdentityQueryBuilder)->planBatches($identities, ['core_business' => 10001], 4, false, boundSourceIdentities: true);

        self::assertSame(['core_business'], $plans[0]['types']);
        self::assertArrayNotHasKey('entities', $plans[0]);
        self::assertSame([['first', 'second'], ['assistant_document']], array_column(array_slice($plans, 1), 'entities'));
        self::assertSame(['budgeting', 'project', 'file_document'], $plans[1]['types']);
        self::assertSame($plans[1]['types'], $plans[2]['types']);
    }

    public function test_expected_and_unbounded_plans_keep_existing_entity_semantics(): void
    {
        $identities = ['first' => ['organization_reporting' => true], 'second' => ['organization_reporting' => true]];
        $builder = new AssistantSourceIdentityQueryBuilder;

        self::assertSame([
            ['types' => ['organization_reporting'], 'entity' => 'first'],
            ['types' => ['organization_reporting'], 'entity' => 'second'],
        ], $builder->planBatches($identities, [], 8, true));
        self::assertSame([['types' => null, 'entity' => null]], $builder->planBatches($identities, [], null, false));
        self::assertSame([['types' => [], 'entity' => null]], $builder->planBatches([], [], 8, false));
        self::assertSame([['types' => ['organization_reporting'], 'entity' => null]], $builder->planBatches($identities, [], 1, false));
    }
}
