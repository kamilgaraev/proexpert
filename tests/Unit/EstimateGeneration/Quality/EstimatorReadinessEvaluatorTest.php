<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimatorReadinessEvaluatorTest extends TestCase
{
    #[Test]
    #[DataProvider('matrix')]
    public function canonical_matrix_has_one_authoritative_result(
        EstimatorReadinessInput $input,
        string $status,
        bool $canGenerate,
        bool $canApply,
        array $blockers,
        array $warnings,
        string $nextAction,
    ): void {
        $result = (new EstimatorReadinessEvaluator)->evaluate($input);

        self::assertSame($status, $result['status']);
        self::assertSame($canGenerate, $result['can_generate']);
        self::assertSame($canApply, $result['can_apply']);
        self::assertSame($blockers, array_column($result['blockers'], 'code'));
        self::assertSame($warnings, array_column($result['warnings'], 'code'));
        self::assertSame($nextAction, $result['next_action']['code']);
        if ($blockers !== []) {
            self::assertFalse($result['can_apply']);
        }
    }

    public static function matrix(): array
    {
        $readyDocuments = self::metrics(documents_total: 1, documents_ready: 1, facts: 2, quantity_takeoffs: 1);
        $cleanDraft = [...$readyDocuments, 'priced_work_items' => 8, 'priced_work_items_total' => 8];

        return [
            'empty' => [self::input(self::metrics()), 'needs_documents', false, false, ['no_documents'], [], 'upload_documents'],
            'pending' => [self::input(self::metrics(documents_total: 2, documents_ready: 1, documents_pending: 1)), 'documents_processing', false, false, ['documents_pending'], ['no_quantity_takeoffs', 'low_document_understanding'], 'wait_documents'],
            'document review' => [self::input(self::metrics(documents_total: 1, documents_ready: 1, documents_action_required: 1, facts: 2)), 'documents_need_review', false, false, ['documents_require_review'], ['no_quantity_takeoffs'], 'review_documents'],
            'ready generation' => [self::input($readyDocuments), 'ready_for_generation', true, false, [], [], 'generate_draft'],
            'no priced positions' => [self::input([...$readyDocuments, 'priced_work_items_total' => 0], true), 'draft_blocked', true, false, ['no_priced_positions'], [], 'review_draft'],
            'norm review' => [self::input([...$cleanDraft, 'normative_requires_review' => 1], true), 'draft_needs_review', true, false, ['norms_require_review'], [], 'review_draft'],
            'quantity review' => [self::input([...$cleanDraft, 'quantity_review_work_items' => 1], true), 'draft_needs_review', true, false, ['quantities_require_review'], [], 'review_draft'],
            'queue review' => [self::input([...$cleanDraft, 'review_items_blocking' => 1], true), 'draft_needs_review', true, false, ['review_items_require_action'], [], 'review_draft'],
            'price review' => [self::input([...$cleanDraft, 'not_calculated_work_items' => 1, 'safe_norm_required_work_items' => 1], true), 'draft_needs_review', true, false, ['prices_require_review'], [], 'review_draft'],
            'quality review' => [self::input([...$cleanDraft, 'duplicate_work_items' => 3], true, 'review_required'), 'draft_needs_review', true, false, ['quality_requires_review'], [], 'review_draft'],
            'quality blocked' => [self::input($cleanDraft, true, 'critical', 'blocked'), 'draft_blocked', true, false, ['quality_blocked'], [], 'review_draft'],
            'ready apply' => [self::input($cleanDraft, true), 'ready_to_apply', true, true, [], [], 'apply_draft'],
            'applied' => [self::input($cleanDraft, true, sessionStatus: 'applied'), 'applied', true, false, [], [], 'open_estimate'],
            'large exact counts' => [self::input([...$cleanDraft, 'facts' => 4_000_000, 'priced_work_items' => 4_000_000, 'priced_work_items_total' => 4_000_000], true), 'ready_to_apply', true, true, [], [], 'apply_draft'],
        ];
    }

    private static function input(
        array $metrics,
        bool $hasDraft = false,
        string $qualityStatus = 'ready',
        string $qualityLevel = 'passed',
        string $sessionStatus = 'ready_to_apply',
    ): EstimatorReadinessInput {
        return new EstimatorReadinessInput($sessionStatus, $hasDraft, $qualityStatus, $qualityLevel, $metrics);
    }

    private static function metrics(
        int $documents_total = 0,
        int $documents_ready = 0,
        int $documents_pending = 0,
        int $documents_action_required = 0,
        int $facts = 0,
        int $drawing_elements = 0,
        int $quantity_takeoffs = 0,
        int $scope_inferences = 0,
    ): array {
        return [
            'documents_total' => $documents_total,
            'documents_ready' => $documents_ready,
            'documents_pending' => $documents_pending,
            'documents_action_required' => $documents_action_required,
            'facts' => $facts,
            'drawing_elements' => $drawing_elements,
            'quantity_takeoffs' => $quantity_takeoffs,
            'scope_inferences' => $scope_inferences,
            'priced_work_items' => 0,
            'priced_work_items_total' => 0,
            'operation_work_items' => 0,
            'quantity_review_work_items' => 0,
            'not_calculated_work_items' => 0,
            'zero_total_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'duplicate_work_items' => 0,
            'normative_requires_review' => 0,
            'review_items_total' => 0,
            'review_items_blocking' => 0,
            'review_items_warning' => 0,
            'review_items_optional' => 0,
            'review_summary_stale' => 0,
            'problem_flags' => 0,
        ];
    }
}
