<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork;

use App\Services\CompletedWork\CompletedWorkHistoryTransformationRules;
use App\Services\CompletedWork\CompletedWorkReconciliationService;
use PHPUnit\Framework\TestCase;

final class CompletedWorkHistoryTransformationRulesTest extends TestCase
{
    public function test_each_t01_category_has_a_deterministic_or_manual_rule(): void
    {
        $service = new CompletedWorkReconciliationService;

        $matching = $service->analyze([$this->row(1, '10.000', '10.0000')], 7, 11)[0];
        $missingCompleted = $service->analyze([$this->row(2, '10.000', null)], 7, 11)[0];
        $missingQuantity = $service->analyze([$this->row(3, null, '8.0000')], 7, 11)[0];
        $conflict = $service->analyze([$this->row(4, '100.000', '10.0000')], 7, 11)[0];
        $invalid = $service->analyze([$this->row(5, '-1', '-1')], 7, 11)[0];
        $duplicate = $service->analyze([
            $this->row(6, '10', '10') + ['journal_work_volume_id' => 22],
            $this->row(7, '10', '10') + ['journal_work_volume_id' => 22],
        ], 7, 11)[0];
        $protectedConflict = $conflict;
        $protectedConflict['protected_history'] = true;

        $this->assertPlan($matching, 'matching', 'record_canonical', false);
        $this->assertPlan($missingCompleted, 'missing_completed_quantity', 'apply', true);
        $this->assertPlan($missingQuantity, 'missing_quantity', 'apply', true);
        $this->assertPlan($conflict, 'quantity_conflict', 'skip_manual', false);
        $this->assertPlan($invalid, 'invalid_quantity', 'skip_manual', false);
        $this->assertPlan($duplicate, 'duplicate_journal_volume', 'skip_manual', false);
        $this->assertPlan($protectedConflict, 'quantity_conflict', 'skip_manual', false);

        $protectedMissing = $missingCompleted;
        $protectedMissing['protected_history'] = true;
        $this->assertPlan($protectedMissing, 'missing_completed_quantity', 'skip_manual', false);

        $already = CompletedWorkHistoryTransformationRules::plan($matching, [
            'rule' => CompletedWorkHistoryTransformationRules::RULE_MATCHING,
            'canonical_quantity' => '10.0000',
        ]);
        self::assertSame('already_transformed', $already['category']);
        self::assertSame('already_done', $already['auto_action']);
        self::assertFalse($already['mutate']);
    }

    public function test_manual_decision_uses_the_chosen_original_or_explicit_value(): void
    {
        $report = (new CompletedWorkReconciliationService)->analyze([
            $this->row(1, '100.000', '10.0000'),
        ], 7, 11)[0];

        self::assertSame('100.0000', CompletedWorkHistoryTransformationRules::canonicalFromDecision($report, 'quantity', null));
        self::assertSame('10.0000', CompletedWorkHistoryTransformationRules::canonicalFromDecision($report, 'completed_quantity', null));
        self::assertSame('12.5000', CompletedWorkHistoryTransformationRules::canonicalFromDecision($report, 'explicit', '12.5'));
        self::assertNull(CompletedWorkHistoryTransformationRules::canonicalFromDecision($report, 'explicit', '-1'));
    }

    private function assertPlan(array $report, string $category, string $action, bool $mutate): void
    {
        $plan = CompletedWorkHistoryTransformationRules::plan($report, null);
        self::assertSame($category, $plan['category']);
        self::assertSame($action, $plan['auto_action']);
        self::assertSame($mutate, $plan['mutate']);
    }

    private function row(int $id, ?string $quantity, ?string $completed): array
    {
        return [
            'id' => $id,
            'organization_id' => 7,
            'project_id' => 11,
            'quantity' => $quantity,
            'completed_quantity' => $completed,
        ];
    }
}
