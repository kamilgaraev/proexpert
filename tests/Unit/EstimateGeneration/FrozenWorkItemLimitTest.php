<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrozenWorkItemLimitTest extends TestCase
{
    public function test_old_frozen_quotes_are_not_repriced_or_given_a_new_limit_at_execution(): void
    {
        self::assertNull(FrozenWorkItemLimit::fromPlan(['contract_version' => 'estimator-processing-plan:v1',
            'work_item_limit' => 1, 'audit_part_limit' => 1]));
        self::assertSame(2, FrozenWorkItemLimit::fromPlan($this->plan()));
        FrozenWorkItemLimit::assertDraft($this->draft(['a', 'b', 'c']), null);
        $this->expectException(InvalidArgumentException::class);
        FrozenWorkItemLimit::assertDraft($this->draft(['a', 'b', 'c']), 2);
    }

    public function test_a_later_part_cannot_ignore_additions_already_accepted_in_an_earlier_part(): void
    {
        $full = $this->draft(['a', 'added:first', 'b']);
        FrozenWorkItemLimit::assertReplacement($full, $this->draft(['b']), ['b'], 3);
        self::assertSame(3, FrozenWorkItemLimit::fromPlan([...$this->plan(), 'work_item_limit' => 3, 'audit_part_limit' => 3]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_items_exceed_approved_plan');
        FrozenWorkItemLimit::assertReplacement($full, $this->draft(['b', 'added:second']), ['b'], 3);
    }

    #[DataProvider('invalidPlans')]
    public function test_new_contract_without_a_real_frozen_upper_bound_fails_closed(array $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_work_approved_plan_invalid');
        FrozenWorkItemLimit::fromPlan([...$this->plan(), ...$change]);
    }

    public static function invalidPlans(): array
    {
        return [[['work_item_limit' => null]], [['work_item_limit' => '2']], [['work_item_limit' => -1]],
            [['work_item_limit' => 513]], [['work_ceiling_policy' => null]], [['contract_version' => 'future']],
            [['audit_part_limit' => 1]], [['audit_part_limit' => null]]];
    }

    private function plan(): array
    {
        return ['contract_version' => 'estimator-processing-plan:v2', 'work_ceiling_policy' => 'global_work_items:v1',
            'work_item_limit' => 2, 'audit_part_limit' => 2, 'roles' => ['estimate_audit' => []]];
    }

    private function draft(array $keys): array
    {
        return ['local_estimates' => [['sections' => [['work_items' => array_map(static fn ($key): array => ['key' => $key, 'item_type' => 'review_note'], $keys)]]]]];
    }
}
