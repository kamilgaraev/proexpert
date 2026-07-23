<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\OperationalReadinessInputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationalReadinessInputFactoryTest extends TestCase
{
    #[Test]
    public function aggregate_adapter_preserves_every_canonical_metric(): void
    {
        $input = (new OperationalReadinessInputFactory)->fromAggregates(
            session: [
                'status' => 'ready_to_apply',
                'has_draft' => true,
                'quality_status' => 'review_required',
                'quality_level' => 'passed',
                'quality_total_work_items' => 12,
                'quality_priced_work_items' => 9,
                'quality_operation_work_items' => 2,
                'quality_quantity_review_work_items' => 1,
                'quality_not_calculated_work_items' => 1,
                'quality_zero_total_calculated_work_items' => 1,
                'quality_safe_norm_required_work_items' => 1,
                'quality_duplicate_work_items' => 2,
                'quality_normative_requires_review' => 1,
                'review_total' => 5,
                'review_blocking' => 2,
                'review_warning' => 2,
                'review_optional' => 1,
                'review_classifier_version' => 2,
                'review_content_version' => 'sha256:'.str_repeat('a', 64),
                'review_source_version' => 'sha256:'.str_repeat('a', 64),
                'review_canonical_input_version' => 'sha256:'.str_repeat('b', 64),
                'review_input_version' => 'sha256:'.str_repeat('b', 64),
                'problem_flags_count' => 3,
            ],
            documents: ['total' => 2, 'ready' => 2, 'pending' => 0, 'action_required' => 0],
            estimate: [],
            sources: ['facts_count' => 100, 'drawings_count' => 20, 'quantities_count' => 30, 'scopes_count' => 4],
        );

        self::assertSame([
            'documents_total' => 2,
            'documents_ready' => 2,
            'documents_pending' => 0,
            'documents_action_required' => 0,
            'facts' => 100,
            'drawing_elements' => 20,
            'quantity_takeoffs' => 30,
            'scope_inferences' => 4,
            'priced_work_items' => 8,
            'priced_work_items_total' => 9,
            'operation_work_items' => 2,
            'quantity_review_work_items' => 1,
            'not_calculated_work_items' => 2,
            'zero_total_calculated_work_items' => 1,
            'safe_norm_required_work_items' => 1,
            'duplicate_work_items' => 2,
            'normative_requires_review' => 1,
            'review_items_total' => 5,
            'review_items_blocking' => 2,
            'review_items_warning' => 2,
            'review_items_optional' => 1,
            'review_summary_stale' => 0,
            'problem_flags' => 3,
        ], $input->metrics);

        $result = (new EstimatorReadinessEvaluator)->evaluate($input);
        self::assertFalse($result['can_apply']);
        self::assertSame([
            'norms_require_review', 'quantities_require_review', 'review_items_require_action',
            'prices_require_review', 'quality_requires_review',
        ], array_column($result['blockers'], 'code'));
    }

    #[Test]
    public function empty_and_large_aggregates_remain_exact_and_nonnegative(): void
    {
        $factory = new OperationalReadinessInputFactory;
        $empty = $factory->fromAggregates(['status' => 'draft'], [], [], []);
        $large = $factory->fromAggregates(
            ['status' => 'ready_to_apply', 'has_draft' => true, 'quality_total_work_items' => 4_000_000, 'quality_priced_work_items' => 4_000_000],
            ['total' => 4_000_000, 'ready' => 4_000_000],
            [],
            ['facts_count' => 4_000_000, 'quantities_count' => 4_000_000],
        );

        self::assertSame(0, $empty->metrics['documents_total']);
        self::assertSame(4_000_000, $large->metrics['priced_work_items']);
        self::assertSame(4_000_000, $large->metrics['facts']);
    }

    #[Test]
    public function priced_traceable_draft_does_not_report_missing_document_quantities(): void
    {
        $hash = 'sha256:'.str_repeat('a', 64);
        $input = (new OperationalReadinessInputFactory)->fromAggregates(
            session: [
                'status' => 'ready_to_apply',
                'has_draft' => true,
                'quality_status' => 'ready',
                'quality_level' => 'passed',
                'quality_total_work_items' => 1,
                'quality_priced_work_items' => 1,
                'review_classifier_version' => 2,
                'review_content_version' => $hash,
                'review_source_version' => $hash,
                'review_canonical_input_version' => $hash,
                'review_input_version' => $hash,
            ],
            documents: ['total' => 1, 'ready' => 1],
            estimate: [],
            sources: [],
        );

        $result = (new EstimatorReadinessEvaluator)->evaluate($input);

        self::assertSame('ready_to_apply', $result['status']);
        self::assertSame([], $result['warnings']);
    }

    #[Test]
    public function stale_review_snapshot_is_blocked_conservatively(): void
    {
        $input = (new OperationalReadinessInputFactory)->fromAggregates(
            ['status' => 'ready_to_apply', 'has_draft' => true, 'review_classifier_version' => 0],
            ['total' => 1, 'ready' => 1],
            [],
            [],
        );
        $result = (new EstimatorReadinessEvaluator)->evaluate($input);

        self::assertSame(1, $input->metrics['review_summary_stale']);
        self::assertContains('review_summary_stale', array_column($result['blockers'], 'code'));
        self::assertFalse($result['can_apply']);
    }

    #[Test]
    public function input_version_mismatch_marks_the_operational_snapshot_stale(): void
    {
        $input = (new OperationalReadinessInputFactory)->fromAggregates([
            'status' => 'ready_to_apply',
            'has_draft' => true,
            'review_classifier_version' => 2,
            'review_content_version' => 'sha256:'.str_repeat('a', 64),
            'review_source_version' => 'sha256:'.str_repeat('a', 64),
            'review_canonical_input_version' => 'sha256:'.str_repeat('b', 64),
            'review_input_version' => 'sha256:'.str_repeat('c', 64),
        ], [], [], []);

        self::assertSame(1, $input->metrics['review_summary_stale']);
        self::assertSame(0, $input->metrics['review_items_blocking']);
    }
}
